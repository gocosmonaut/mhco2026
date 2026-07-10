# LSCache: `.htaccess` requirements and common pitfalls

Field-tested guide to wiring `drupal/lscache` correctly under LiteSpeed
Web Server, OpenLiteSpeed, and managed LiteSpeed PaaS hosts. Covers the
`.htaccess` directives the module assumes, how to verify caching is
actually happening, and the failure modes that explain 90% of "the
module does nothing" reports.

## TL;DR

The Drupal LSCache module **only emits headers**. LSWS decides
whether to honour them based on directives the operator
configures on the server side. The module-emits-the-header /
LSWS-must-be-told pattern recurs in several shapes; each gets a
section below.

- **`CacheLookup` in `.htaccess`** is the headline gate. Without
  it, LSWS never consults its cache for the vhost. On most
  shared and PaaS hosting, `.htaccess` is the only place you can
  add the directive. If `x-litespeed-cache` is missing from
  responses, check `.htaccess` first; nine times out of ten that
  is the problem.
- **`CacheVary cookie=` directives** must match what the
  response subscriber emits when private-cache mode or operator-
  configured vary cookies are in use; otherwise LSWS keys cache
  entries identically for variants the module told it to
  separate (the auth-served-as-anonymous bug class).
- **`Host` header on outgoing PURGE requests** must match the
  canonical site host, not the localhost URL the purger targets;
  otherwise PURGE evicts a phantom localhost cache bucket and
  editor saves take the full max-age to propagate.
- **Tag-based PURGE** is silently ignored on some LSWS builds
  (confirmed on LSWS Enterprise 6.3.4): the PURGE returns HTTP 200
  but evicts nothing, so content edits never clear. Run
  `drush lscache:diag` to detect it; the `url` / `auto` purge
  strategy then sends one PURGE per resolved URL, which every
  probed build honours.
- **BigPipe** in Drupal core streams placeholders at PHP
  runtime, which LSWS bypasses on cache hits. From 1.3.0-beta4
  the module auto-suppresses LSCache emission when BigPipe
  placeholders are present in the response body, so this case
  no longer needs operator action.

The status report at *Reports &rsaquo; Status report*
(`/admin/reports/status`) surfaces every one of these except the
automatic BigPipe case as rows with copy-pasteable remediation
when it detects a problem. Check it before opening an issue.

## What the module does and does not do

When enabled, `drupal/lscache` injects two response headers on
cacheable responses:

- `X-LiteSpeed-Cache-Control: public,max-age=N`
- `X-LiteSpeed-Tag: tag1,tag2,...`

That is all. It **does not** configure LSWS, touch `.htaccess`, or
verify that the webserver is actually LSWS. LSWS decides whether to
consult its cache for a request based on a vhost-level directive that
the module has no access to from PHP on most hosted environments.

## The directives you need

```apache
<IfModule LiteSpeed>
  CacheLookup public on
  php_flag output_buffering off
</IfModule>
```

Place this at the top of `.htaccess`, or in `<Directory>` /
`<VirtualHost>` scope in the LSWS config if you have root. The
`<IfModule>` guard means it is a no-op under plain Apache, so it is
safe to ship in repo-managed `.htaccess`.

Both directives matter:

- `CacheLookup public on` tells LSWS to consult its cache for public
  responses on this vhost.
- `php_flag output_buffering off` ensures PHP flushes response headers
  before body streaming begins, so LSWS sees the `X-LiteSpeed-*`
  headers in time to make a caching decision. Without it, LSWS often
  returns `x-litespeed-cache: miss` on every request even though the
  Drupal module's headers are otherwise correct.

### Jelastic, Virtuozzo Application Platform, UKHost4U, managed LSWS PaaS

You almost certainly do not have root access to
`/usr/local/lsws/conf/`. Adding the directives to `.htaccess` is the
only path. LSWS reads `.htaccess` exactly like Apache does.

### LSWS Enterprise on a host you own

You can put the directives in the vhost config and skip `.htaccess`,
but shipping the `.htaccess` block is still the lowest-friction path:
it works everywhere.

### OpenLiteSpeed

Same directives, same placement, same `<IfModule>` guard.

## How to verify LSWS is actually caching

Two signals, in order of reliability:

1. **Response header**: `x-litespeed-cache: hit` on a warm request, or
   `x-litespeed-cache: miss` on a cold one. If the header is missing
   entirely, LSWS is not processing the request through its cache
   layer; the vhost directive is not active.
2. **TTFB drop**: a warm LSCache hit should return in under 50ms for
   static HTML, typically single-digit ms on a quiet server. If your
   TTFB stays in the 200-400ms Drupal-bootstrap range across repeated
   requests to an identical URL, something is bypassing LSCache.

### Caution: Cloudflare and some other CDNs strip the header

If you test via your public hostname and see no `x-litespeed-cache:`
header but the site feels fast anyway, the CDN in front of LSWS may be
removing the header in transit. Test direct to origin to confirm:

```bash
curl -sI --resolve www.example.com:443:127.0.0.1 \
  "https://www.example.com/" -k
```

Run the curl on the LSWS host itself. If you see `x-litespeed-cache`
here but not through the CDN, the CDN is stripping. Annoying but
benign.

## LSWS-side PURGE handling

`drupal/lscache_purger` issues HTTP PURGE requests to evict tagged
cache entries when content changes. The module is responsible for
forming a correct request; LSWS is responsible for honouring it. The
two responsibilities split cleanly except in one operator-facing
trap: **LSWS responds with `200 Purged` to any PURGE request it
accepts at the protocol level, regardless of whether the server-side
configuration actually permits that PURGE to evict cached entries.**
This trips operators who assume `200 Purged` means "entry evicted"
and stop investigating when the cache stays stale.

On most LSWS installs (single-server, default config), 200 means
both. On managed-LSWS environments (Jelastic, Cloudways, etc.) and
on LSWS Enterprise installs with restrictive admin-port routing,
200 can mean "accepted" without meaning "evicted." If you suspect
the difference, the diagnostic is: capture the response's
`Last-Modified` before and after a PURGE; if it's unchanged on the
next request, the PURGE was accepted but didn't take effect.

### LSWS configuration requirements

For tag-based PURGE to actually evict entries, LSWS needs:

1. **A purge target the request can reach.** Most operators set
   `lscache_purger.settings:purge_host` to `http://localhost/` so the
   PURGE never leaves the box and never goes through a CDN. Setting
   it to the public site URL is the most common operator mistake;
   any CDN in front of LSWS will reject the non-standard PURGE
   method with HTTP 400 (the new failure-reason output in 1.3.0
   names this specifically when it sees a `CF-Ray` response header).

2. **Permission to act on tag-based PURGE.** Some LSWS configurations
   require PURGE requests to land on the admin port (typically 7080)
   rather than the standard application port. Consult your LSWS
   vendor or hosting provider if `Last-Modified` doesn't advance
   after a known-good PURGE.

3. **The directives this module assumes** (`CacheLookup public on`
   plus the private/vary additions when those features are
   configured). Without `CacheLookup`, LSWS doesn't consult its
   cache for the vhost; without it, PURGE has nothing to evict
   because there's nothing stored.

### Operator workaround when tag-PURGE doesn't take effect

If you've confirmed PURGE returns 200 but `Last-Modified` doesn't
advance, the immediate remediation paths are:

- Flush LSCache via your hosting provider's admin UI (the
  configuration that controls remote-PURGE is the same one that
  controls admin-UI flush).
- Restart LSWS (drops the cache wholesale).
- Set `default_ttl` to a lower value to bound the staleness window
  while you work with your provider on the server-side fix.

`drupal/lscache` itself can't make LSWS-side PURGE work. The
module's responsibility is forming a correct request, surfacing the
HTTP-level response honestly, and giving operators the diagnostic
context (server header, CF-Ray, status code) needed to identify
where the chain breaks.

## PURGE Host header (LSWS keys cache by Host)

LSWS keys its cache entries by the HTTP `Host` header. Real
traffic populates entries under your canonical site host (e.g.
`Host: www.example.com`). When the purger sends a PURGE request,
Guzzle derives the Host header from the request URL by default,
so a PURGE to `http://localhost/` carries `Host: localhost`. LSWS
evicts a phantom localhost cache bucket; the real
`www.example.com` entries stay live until their max-age expires.

The 1.3.0-rc2 portal-master diagnosis surfaced this as chronic
"cache doesn't clear after node edits" reports: the purger was
returning HTTP 200 (PURGE accepted) but no real entries were
being evicted, so editors saw stale content for the configured
24-hour max-age. Confirmed end-to-end:

```bash
# Wrong: Guzzle defaults Host to localhost, evicts phantom bucket
curl -X PURGE -H "X-LiteSpeed-Purge: node:521" http://localhost/
# -> 200, but no real eviction

# Right: explicit Host header matches canonical-host cache key
curl -X PURGE -H "Host: www.example.com" \
  -H "X-LiteSpeed-Purge: node:521" http://localhost/
# -> 200, real entry evicted
```

From 1.3.0-beta6 the purger supports a `purge_host_header` config
key. When set, the purger overrides the `Host` header on outgoing
PURGE requests so LSWS evicts entries keyed under the canonical
site host. Set it at
*Configuration &rsaquo; Development &rsaquo; Performance &rsaquo;
LSCache Purger* or via drush:

```
drush config:set lscache_purger.settings purge_host_header "www.example.com" -y
drush cr
```

Enter a bare hostname, not a URL. The form validator rejects
values with a scheme or path. The
`/admin/reports/status` row **LSCache purger Host header
configuration** warns when:

- `purge_host` targets localhost / 127.0.0.1 / [::1], and
- `purge_host_header` is unset, and
- the site's canonical host (from the current request or
  `$base_url`) is non-localhost.

Leave `purge_host_header` blank if your LSWS install genuinely
keys cache under localhost (some containerised dev setups, where
the Docker network shape collapses everything to localhost). The
status row recognises that case and stays silent.

The dependency-on-the-server-side pattern from elsewhere in this
doc applies in reverse here: the module emits the right Host
header when configured, but LSWS still needs to be reachable at
`purge_host` and configured to accept PURGE for the tags. The
companion sections **LSWS-side PURGE handling** and **Authenticated
cache differentiation** cover the other two halves of the
invalidation chain.

## LSCache vs Drupal's dynamic_page_cache `UNCACHEABLE` hint

Drupal's Dynamic Page Cache module emits an
`x-drupal-dynamic-cache: UNCACHEABLE` response header on pages that
its render logic deems uncacheable (typically because they hit a
late-render placeholder that varies per request). `drupal/lscache`
**does not** look at that header. It looks at the response's
`Cache-Control` header (via `Response::isCacheable()`), which is the
HTTP-level cacheability decision.

In practice these usually agree: if Drupal marks a response
UNCACHEABLE, the `Cache-Control` header should also be `private` or
`no-cache`. But in some configurations (custom render policies,
modules that set Cache-Control independently of Drupal's cacheability
metadata), the two can disagree: Drupal says UNCACHEABLE but
Cache-Control still says `public,max-age=N`. In that case LSCache
caches the response, because it follows the HTTP contract.

If you have content with late-render personalisation that needs to
NOT be cached at the LSWS layer, set the response's Cache-Control
explicitly via a response policy:

```php
// In a kernel.response subscriber on your custom code:
if ($this->routeMatch->getRouteName() === 'my_module.personalised') {
  $response->setMaxAge(0);
  $response->headers->set('Cache-Control', 'private, no-store');
}
```

Or, more idiomatically for blocks and render arrays, mark
`#cache.max-age` to `0` and the rest of the Drupal stack follows.

## Authenticated cache differentiation (`CacheVary cookie=` directives)

When private-cache mode is on (`lscache.settings:private_cache.enabled
= TRUE`), the response subscriber emits an `X-LiteSpeed-Vary:
cookie=<session_cookie_name>` header to tell LSWS that the cache
entry's key should include the session cookie's value. Without this,
LSWS hashes all requests for a given URL to the same cache key and
serves whichever response was cached first to every subsequent
requester, regardless of whether they are anonymous or
authenticated. The 1.3.0-beta4 portal-master retest surfaced this:
anonymous requests populated the homepage cache, and subsequent
authenticated admin requests hashed to the same key and got the
anonymous body without ever reaching Drupal.

From 1.3.0-beta5 the module auto-discovers the session cookie name
via Drupal core's `SessionConfiguration` service and appends it to
the vary list whenever private-cache mode is enabled. Operators do
not need to add the cookie name to the `vary_cookies` config list
manually.

**But LSWS only honours the header when the matching server-side
directive is configured.** This is the same pattern as the
operator-configured vary cookies: the module emits the header; the
server has to be told to act on it. For the session cookie, add a
`CacheVary cookie=<name>` directive to your `.htaccess` (or vhost
config) inside the `<IfModule LiteSpeed>` block. To discover the
exact cookie name your install uses (it varies by site URL hash):

```bash
drush ev '$req = Drupal::request(); echo Drupal::service("session_configuration")->getOptions($req)["name"];'
```

Then add the matching directive. If you also have operator-
configured vary cookies, combine them on one line:

```apache
<IfModule LiteSpeed>
  CacheLookup public on private on
  php_flag output_buffering off
  CacheVary cookie=SSESS_abc123,device_class
</IfModule>
```

The status report row at *Reports &rsaquo; Status report* shows
whether the directive list and the emitted-cookie list agree, with
a copy-pasteable suggestion when they don't. Look for the **LSCache
vary-cookie configuration** row.

The bug class is general: any URL where Drupal returns different
responses to anonymous vs authenticated users is susceptible until
both sides of the chain are in place. The homepage is the obvious
example; less obvious is `/admin`, which serves an anon-cacheable
403 response to anonymous visitors and a different page to
authenticated administrators. The post-beta5 protocol assumes both
sides are configured; if you see the status row warning, treat
that as gating any soak or production use of private-cache mode.

## BigPipe interaction (automatic suppression on placeholder responses)

Drupal's BigPipe module replaces slow-rendering placeholders at
streaming time inside PHP. The replacement happens during
`Response::send()`; the rendered HTML observable before that point
still carries the placeholder markup (span tags with
`data-big-pipe-placeholder-id` attributes). If a cache layer in
front of PHP stores the pre-replacement body and replays it on a
subsequent request, the cache bypasses PHP entirely and BigPipe
never runs. Authenticated content rendered via BigPipe placeholders
(the admin toolbar, user-account widgets, `#lazy_builder` outputs
that BigPipe streams) goes missing from the cached response, and
subsequent requests receive the anonymous-shape body regardless of
who is requesting it.

The 1.3.0-beta3 portal-master retest surfaced exactly this:
authenticated administrators hitting a publicly-cached page saw the
anonymous-shape body because the placeholders had been cached
unresolved.

**Automatic behaviour from 1.3.0-beta4 onwards.** The response
subscriber scans the response body for the
`data-big-pipe-placeholder-id` marker. When detected, it emits
`X-LiteSpeed-Cache-Control: no-cache` to actively suppress LSWS-side
caching for that response. Active suppression is necessary because
the HTTP `Cache-Control` header is still `public,max-age=N` (the
response is HTTP-cacheable in the abstract); a soft skip would let
LSWS fall back to that header and cache the response anyway.

Operators do not need to configure anything. Responses without
BigPipe placeholders continue to cache normally.

**For pages where you want both LSCache and per-user content.**
BigPipe and LSCache are fundamentally incompatible on the same
response, but `drupal/lscache` ships an alternative for this use
case: the `#type: 'lscache_esi'` render element from 1.2.x. ESI
fragments are like BigPipe placeholders in spirit (a per-user chunk
embedded in an otherwise-cacheable page) but resolved by LSWS at
the cache layer rather than by PHP at streaming time. The
surrounding page can stay in shared public cache; the fragment
resolves per-user from a token-signed sub-request.

To migrate a BigPipe-fed region (cart count, welcome banner,
notification badge) to an LSCache-compatible equivalent:

```php
// Before: rendered via #lazy_builder; BigPipe streams it.
$build['cart_count'] = [
  '#lazy_builder' => ['my_module.cart:render', [$user_id]],
];

// After: rendered via #lscache_esi; LSWS holds it per-user.
$build['cart_count'] = [
  '#type' => 'lscache_esi',
  '#callback' => 'my_module.cart:render',
  '#args' => [$user_id],
];
```

The callback class must implement `\Drupal\Core\Security\TrustedCallbackInterface`
(see README's ESI section). All other code stays the same.

## Browser vs server page-cache TTL

Two separate TTLs govern how long a page is considered fresh, and
operators routinely collapse them into one "cache TTL":

- `system.performance:cache.page.max_age` sets the **browser**-facing
  `Cache-Control: max-age`. Browsers honour it, and it cannot be
  purged: nothing can reach out and evict a page a visitor's browser
  already holds.
- `lscache.settings:default_ttl` sets the `X-LiteSpeed-Cache-Control`
  max-age that LSWS caches by. The purger evicts this the instant
  content changes.

This bites in both directions:

- **Browser TTL of 0.** Drupal marks anonymous responses
  `no-cache, private`, so the module never emits public LSCache headers
  and LSWS caches nothing, despite the module being enabled. This is one
  root of the "module is on but nothing is cached" report; the other is
  a missing `CacheLookup` directive (above).
- **Browser TTL set very high** (often to silence Purge's own "page
  cache maximum age" nudge). The purger evicts the LSWS copy the instant
  content changes, but it cannot purge browsers, so a returning visitor
  stays pinned to the stale page for up to that long. A content update
  can look "stuck" until a hard refresh.

The recommended shape is a **short browser TTL alongside a longer,
purge-managed server TTL**: LSWS serves everyone fast from RAM, the
purger keeps the server copy fresh on every edit, and returning visitors
pick up changes on their next request rather than waiting out a long
browser max-age. Five minutes for the browser TTL is plenty.

The **Default TTL** field on the LSCache settings form documents this
distinction where you set it, and the `/admin/reports/status` row
**LSCache page-cache TTL (browser vs server)** warns if the browser TTL
is 0 or longer than a day. When the TTLs are in a sensible shape the row
stays quiet.

## Common failure modes

### 1. Module emits headers but no caching

**Symptom**: `X-LiteSpeed-Cache-Control` and `X-LiteSpeed-Tag` are
present in responses, but no `x-litespeed-cache:` header, and TTFB
does not drop on repeat requests.

**Cause**: `CacheLookup` directive missing from `.htaccess`, or the
`php_flag output_buffering off` directive missing.

**Fix**: add the block above to `.htaccess`, reload LSWS.

### 2. .htaccess edits do not survive composer install

The Drupal module does not manage `.htaccess`. If you use
`drupal/recommended-project` or similar, `.htaccess` is regenerated on
every `composer install` from core's scaffold, dropping any manual
edits. Two ways to fix this:

**(a) Commit `.htaccess` manually after editing** and mark it as
overridden via composer-scaffold `file-mapping`:

```json
"extra": {
    "drupal-scaffold": {
        "file-mapping": {
            "[web-root]/.htaccess": false
        }
    }
}
```

**(b) Use scaffold's `append` feature** to append the LSCache block
from a repo file on every composer install:

```json
"extra": {
    "drupal-scaffold": {
        "file-mapping": {
            "[web-root]/.htaccess": {
                "append": "scripts/htaccess-lscache-append.txt"
            }
        }
    }
}
```

Where `scripts/htaccess-lscache-append.txt` contains the
`<IfModule LiteSpeed>` block. This survives every composer install
and does not require committing `web/.htaccess` to git.

### 3. Coexisting with other LSCache or caching modules

If you previously ran `litespeedtech/lscache-drupal` (the
GitHub-only legacy module), uninstalling it may remove its own
`<IfModule LiteSpeed>` block from `.htaccess`. **If that block was
the only `CacheLookup` directive, caching stops** the moment you
uninstall the legacy module, leaving `drupal/lscache` emitting
headers into the void.

Verify `.htaccess` post-uninstall:

```bash
grep -A3 "IfModule LiteSpeed" web/.htaccess
```

If nothing returns, re-add the block.

### 4. LSCache caching heuristics may delay first-hit caching

LSWS can be configured with smart-caching directives (such as
`CacheResp`) that require multiple requests to the same URL before
LSWS commits to caching it. If your host applies such a configuration,
the first few requests may serve from origin even with everything
otherwise correct.

This is configuration-dependent, not universal. On a default LSWS
install, a `miss` on request 1 should become a `hit` on request 2
without intervention. If you see steady `miss` across 10+ identical
requests direct-to-origin, the issue is not warm-up; investigate the
other failure modes in this document.

### 5. Cloudflare or other CDN header stripping

Some CDN configurations strip `x-litespeed-cache` in transit. This is
a reporting-header issue, not a caching failure. Confirm via
direct-to-origin curl (see above).

## Verifying before you file a bug

Before reporting "the module does not cache", run this checklist:

1. `grep -A3 "IfModule LiteSpeed" web/.htaccess`. Does the block
   exist?
2. `grep "CacheLookup" web/.htaccess`. Is `CacheLookup public on`
   present? (Just `CacheLookup on` may not be enough on some LSWS
   versions.)
3. `grep "output_buffering" web/.htaccess`. Is
   `php_flag output_buffering off` present?
4. LSWS version: `/usr/local/lsws/bin/lshttpd -v`. LSWS 5.2.3 or later
   required.
5. Module enabled: `drush pml | grep lscache`.
6. Module enabled in config: `drush config:get lscache.settings enabled`.
7. Restart LSWS after any `.htaccess` changes:
   `jem service restart lsws` (Jelastic) or `systemctl restart lsws`
   (root access). LSWS sometimes caches the parsed `.htaccess` itself
   and needs prompting to re-read.
8. Direct-to-origin curl, bypassing CDN. Does `x-litespeed-cache:`
   appear on the second or third identical request?

If all eight check out and you still have no caching, file the bug
with the curl output, the `.htaccess` excerpt, and your LSWS version.

## Version notes

### 1.0.0-alpha1

Header injection only. The `lscache_purger` submodule's `invalidate()`
method is a stub that returns FAILED for every request and writes no
log entry, so node edits do not evict cached pages until `default_ttl`
expires. During alpha1 testing, set `default_ttl` to a low value
(60-300s) to bound staleness, or run a manual `/lscpurgeall` via
the legacy `lite_speed_cache` module if you have it installed. Tag
filtering and the per-install prefix are not applied; the
`X-LiteSpeed-Tag` header carries the full raw Drupal tag list.

### 1.0.0-alpha2

Header injection now produces LSCache hits end to end (with the
`output_buffering off` directive present). Tag header is filtered by
default to drop tags that add no invalidation value (`config:*`,
`user:*`, `*_view`, `*_list`, `http_response`, `rendered`); a
per-install hash prefix is applied automatically when `tag_prefix`
config is empty. Enabling `lscache_purger` auto-wires the
minimum-viable Purge pipeline (purger + queuer + processor) and
failures log to the `lscache_purger` channel at WARNING.

**Known issue (fixed in alpha3)**: the alpha2 HTTP purger fails for
cron-driven invalidation because it cannot resolve the LSCache host
in CLI context. Symptom: `drush p:invalidate` and node-edit-driven
queue draining return FAILED for every tag. Workaround: upgrade to
alpha3 or set `lscache_purger.settings:purge_host` explicitly via
`drush config:set` and apply the purger code from alpha3 manually.

### 1.0.0-alpha3

HTTP purger now works under cron processing. The purger resolves the
LSCache host from (in order) the `lscache_purger.settings:purge_host`
config key, the current Drupal request host (web context), or the
site's `$base_url` (CLI context). When none is available, the
operator gets an actionable error message naming the config key.

A new settings form at
`/admin/config/development/performance/lscache/purger` exposes
`purge_host` and `timeout` for the submodule.

The Drupal status report (`/admin/reports/status`) now inspects
`.htaccess` and reports whether the required directives are present.
Missing `CacheLookup public on` is ERROR; missing
`php_flag output_buffering off` is WARNING.

**Known issues (fixed in alpha4)**: the alpha3 status report check
caps the `.htaccess` read at 8KB and returns false ERROR when the
LSCache directives sit past that offset (which is exactly where
drupal-scaffold's append feature places them). The alpha3 form copy
also recommends the public site URL as `purge_host`, which fails
silently behind any CDN because CDNs reject the PURGE method.

### 1.0.0-alpha4 / 1.0.0-beta1

Status report check now reads the full `.htaccess` so directives at
any offset are detected correctly.

`purge_host` form copy and README explicitly call out the CDN
incompatibility (Cloudflare, Fastly, CloudFront, Bunny, Akamai all
reject PURGE) and recommend origin-direct URLs (`http://127.0.0.1`
for single-server, internal hostnames otherwise).

A new "Test purge host" button on the settings form issues a real
PURGE to the entered URL and surfaces the result, with a specific
error path for Cloudflare's HTTP 400 + `CF-Ray` rejection signature.

`1.0.0-beta1` carries the same code as `1.0.0-alpha4` with the
version bump signalling API freeze.

**Known issue (fixed in beta2)**: beta1 emits
`X-LiteSpeed-Cache-Control: public,max-age=N` and `X-LiteSpeed-Tag`
on responses Drupal has marked uncacheable (admin pages, authenticated
user responses, anything carrying `Cache-Control: private` or
`no-cache`). LSWS bypasses caching on those responses anyway because
it sees the session cookie, but the headers are misleading and would
become a problem if LSWS-side cookie bypass ever weakened. Upgrade
to beta2 for the fix.

### 1.0.0-beta2

Response subscriber now defers to Drupal's HTTP cacheability decision
(`Response::isCacheable()`) before emitting any LSCache header.
Private, no-cache, and no-store responses are passed through
untouched. Verified by five new unit tests covering each
cacheable/uncacheable case.

### 1.0.0-rc1 / 1.0.0

Same code as `1.0.0-beta2` (rc1 carried only a cosmetic info.yml
description polish; 1.0.0 stable carried the same code as rc1). API
freeze and Drupal Security Advisory coverage opt-in landed at the
1.0.0 cut. Subsequent 1.0.x releases are bug-fix only; new feature
work moves to 1.1.x and later.

### 1.0.1

Soft-hardening from the pre-stable security review. The
`purge_host` form field now validates the URL scheme, accepting
only `http://` and `https://` (Drupal's url element type otherwise
accepts `file`, `ftp`, `ldap`, etc.). Operator-facing only;
permission-gated by `administer lscache`. No exploitable bug
identified at 1.0.0; defence in depth.

### 1.1.x (private cache for authenticated users)

Per-user private-cache mode. Authenticated user pages, which 1.0.x
passed through to PHP, can now be held in LSCache's per-user
private cache. The response subscriber detects per-user cache
contexts (`user`, `user.permissions`, `user.roles`, `session`,
configurable) and emits `X-LiteSpeed-Cache-Control: private,max-age=N`
automatically. Off by default; enabling private-cache mode
requires updating the `.htaccess` directive from
`CacheLookup public on` to `CacheLookup public on private on`.

Admin routes are excluded from both public and private modes via
`AdminContext::isAdminRoute()`, so settings forms and admin
listings are never cached even when they happen to look cacheable
to Drupal's HTTP-cacheability check.

The `1.1.x` branch is frozen at `1.1.0-beta1` per the branch-
consolidation decision in 1.3.0-beta2; operators should install
the cumulative `1.3.x` line, which carries every 1.1 feature
forward.

### 1.2.x (ESI / Edge Side Includes)

A `lscache_esi` render element emits `<esi:include src="..." />`
tags that LiteSpeed processes server-side, fetching each fragment
from a token-signed route and stitching it back into the
surrounding response. Useful for pages that are mostly public-
cacheable but contain a small per-user fragment (cart count,
welcome banner, notification badge). Builds on 1.1.x's private-
cache machinery: each fragment is private-cached per user, while
the surrounding page stays in shared public cache.

The fragment route is gated by HMAC-SHA256 signature verification
plus `\Drupal\Core\Security\TrustedCallbackInterface` enforcement
on the resolved callback class. Fragment authors must declare
their renderer class as a trusted callback, the same policy
Drupal core enforces on `#lazy_builder`.

Frozen at `1.2.0-beta1` per branch consolidation; install `1.3.x`
to get the ESI surface alongside everything else.

### 1.3.x (vary cookies)

Named vary-cookie support. Operators configure cookie names at
*Configuration > Development > Performance > LSCache*; for every
configured cookie, LSCache emits
`X-LiteSpeed-Vary: cookie=NAME` on every cacheable response. LSWS
then keys its cache entry by cookie value, holding a separate
entry per variant. Useful for mobile vs. desktop, currency,
country, A/B test buckets.

**Required matching LSWS server-side directive:** the module-side
`X-LiteSpeed-Vary` header is necessary but not sufficient. LSWS
must also be told to honour the vary cookie via a matching
`CacheVary cookie=NAME` directive at the server or vhost level
(not in `.htaccess`, since `CacheVary` is rejected there on most
LSWS distributions). Without this, the header is emitted
correctly but LSWS will not actually key its cache by cookie
value.

The `1.3.x` line is the canonical active development branch as of
`1.3.0-beta2`. It contains all of 1.0.x stable code, all of 1.1.x
private cache, all of 1.2.x ESI, plus 1.3.x vary cookies.

### Summary of `.htaccess` directives by feature surface

| Configuration                         | `.htaccess` directives required                   |
| ------------------------------------- | ------------------------------------------------- |
| Public cache only (1.0.x baseline)    | `CacheLookup public on` + `php_flag output_buffering off` |
| Public + private cache (1.1.x+)       | `CacheLookup public on private on` + `php_flag output_buffering off` |
| Public + private + ESI (1.2.x+)       | Same as private; LSWS-side `Esi on` for vhost       |
| Public + private + vary cookies (1.3.x+) | Same as private; LSWS-side `CacheVary cookie=NAME` for vhost |

Note that `Esi on` and `CacheVary` directives must be placed in the
LSWS server config or vhost config, not `.htaccess`, on most LSWS
distributions.
