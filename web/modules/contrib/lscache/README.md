# LSCache

## Introduction

LSCache is a Drupal.org-native integration for
[LiteSpeed Cache](https://www.litespeedtech.com/products/litespeed-web-server/features/cache).
It adds the headers LiteSpeed needs to cache Drupal pages keyed by
cache tag, then invalidates those pages from Drupal when the underlying
content changes.

Two modules ship in this package:

- **LSCache**: the core integration. Adds `X-LiteSpeed-Tag` and
  `X-LiteSpeed-Cache-Control` response headers on cacheable responses
  so LiteSpeed knows what to cache and how to tag it.
- **LSCache Purger**: a
  [Purge framework](https://www.drupal.org/project/purge) plugin that
  issues tag-based invalidation requests to LiteSpeed when cache
  invalidation events fire in Drupal.

See [ROADMAP.md](ROADMAP.md) for the planned feature trajectory.

## Requirements

- Drupal 10.3 or later, or Drupal 11.
- LiteSpeed Web Server or OpenLiteSpeed with LSCache enabled **and
  a `CacheLookup` directive active for your virtual host** (see the
  next section).
- [Purge](https://www.drupal.org/project/purge) module (only required
  to enable the `lscache_purger` submodule).

## Critical: the `.htaccess` requirement

This module only emits response headers. LSWS will not actually cache
anything unless it sees a `CacheLookup` directive for your virtual
host. On most shared / PaaS hosting (Jelastic, Virtuozzo, managed LSWS
providers), the only place you can add this directive is `.htaccess`.

Add this block to the top of your site's `.htaccess`:

```apache
<IfModule LiteSpeed>
  CacheLookup public on
  php_flag output_buffering off
</IfModule>
```

Both directives are required. `CacheLookup public on` tells LSWS to
consult its cache for public responses. `php_flag output_buffering off`
ensures PHP flushes response headers before body streaming begins, so
LSWS sees the `X-LiteSpeed-*` headers in time to make a caching
decision. Without the buffering directive, LSWS is likely to return
`x-litespeed-cache: miss` on every request even when your module and
cache directives are otherwise correct.

The `<IfModule>` guard means it is a no-op under plain Apache, so it
is safe to commit in repo-managed `.htaccess`.

If you use `drupal/recommended-project`, `.htaccess` is regenerated
on every `composer install` from core's scaffold. Use scaffold's
`append` feature to re-apply the block automatically:

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
`<IfModule LiteSpeed>` block above.

For detailed troubleshooting (Cloudflare header stripping, coexistence
with other LSCache modules, diagnostic checklist), see the
[`.htaccess` gotchas guide](docs/htaccess-gotchas.md).

### Verifying LSWS is caching

After installation, check for the `x-litespeed-cache` response header:

```bash
# Expect "miss" on first request, "hit" on second
curl -sI "https://yoursite.example/" | grep -i x-litespeed-cache
curl -sI "https://yoursite.example/" | grep -i x-litespeed-cache
```

If the header is **missing entirely**, LSWS is not consulting its
cache: usually the `CacheLookup` directive is missing or not active.

## Installation

```bash
composer require drupal/lscache
drush en lscache
```

To wire invalidation into the Purge pipeline:

```bash
composer require drupal/purge
drush en lscache_purger
```

That single `drush en` also enables `purge_queuer_coretags`,
`purge_processor_cron`, and `purge_processor_lateruntime` (all
declared as submodule dependencies) and auto-configures a
minimum-viable Purge pipeline: `lscache` purger + `coretags`
queuer + `cron` and `lateruntime` processors. Tag-based
invalidations from node edits queue automatically and drain at
the end of each Drupal request via the lateruntime processor, so
editor saves reach LSWS within the same request rather than
waiting on the next cron run. Cron remains as a fallback for
sites with low traffic.

**Set the LSCache purge host** at *Configuration > Development >
Performance > LSCache Purger*
(`/admin/config/development/performance/lscache/purger`). This is
required for cron-driven purging because the cron processor runs from
the CLI, where the module cannot auto-detect the request host.

The purge host must use the `http://` or `https://` scheme and should
be an **origin-direct URL** that PHP can reach directly, not a public
URL that goes through a CDN:

- Single-server install (Drupal and LSWS on the same box): use
  `http://127.0.0.1`.
- Multi-server install: use the LSWS server's internal hostname,
  e.g. `http://lsws.internal`.
- Behind Cloudflare, Fastly, CloudFront, or any CDN: **do not** use
  your public site URL. CDNs reject the non-standard PURGE method
  with HTTP 400 before the request reaches origin, and the purger
  fails silently with no cache eviction. Use the origin's internal
  hostname or `http://127.0.0.1` instead.

The settings form has a **Test purge host** button that issues a real
PURGE request and reports the response. Use it before relying on a
new configuration; a CDN-rejected PURGE will be obvious from the
test result.

Review the rest of the configuration at
`/admin/config/development/performance/purge`. The auto-configuration
only runs when the site does not already have purgers, queuers, or
processors set up; it never overwrites operator customisation.

If you want to drain the purge queue manually (for example after a
bulk content import) rather than waiting for cron, install the
`purge_drush` submodule and add its processor:

```bash
drush en purge_drush
drush p:processor-add drush_purge_queue_work
drush p:queue-work
```

`purge_drush` is not enabled by default because most sites only need
the cron-driven path that `lscache_purger` auto-configures.

## Verifying the install

After enabling the module, visit *Reports > Status report*
(`/admin/reports/status`) and look for the **LSCache .htaccess
directives** row. It will report:

- **OK** if both required directives are present in `.htaccess`
- **Warning** if `CacheLookup public on` is present but
  `php_flag output_buffering off` is not (caching may be unreliable)
- **Error** if `CacheLookup public on` is missing (caching cannot work)

Address any error or warning before opening an issue, since these
directives account for the vast majority of "module is enabled but
nothing is cached" reports.

Alternative combinations exist for URL-based purging, regex purging,
or late-runtime processing; see the
[Purge documentation](https://www.drupal.org/project/purge) for the
full matrix.

## Configuration

Navigate to **Configuration > Development > Performance > LSCache**
(`/admin/config/development/performance/lscache`).

### Enable LSCache header injection

When enabled, every cacheable response receives headers telling
LiteSpeed how long to cache the response and which Drupal cache tags
it carries. Leave disabled during initial installation and enable
after confirming LiteSpeed is running in front of the site.

**What this toggle does and does not do.** The toggle controls
whether this module emits `X-LiteSpeed-Cache-Control` and
`X-LiteSpeed-Tag` headers. It does not control LSWS-side caching.
With the `.htaccess` `CacheLookup public on` directive in place, LSWS
will continue to cache responses on its own heuristics even when this
module is "disabled"; the module simply stops contributing tags and
TTL hints to those responses.

If you want to fully stop LSWS caching for a debugging or rollback
window (for example, to confirm a misbehaviour is or is not coming
from the cache layer), comment out the `CacheLookup` directive in
`.htaccess` rather than just toggling this module off. The
[`.htaccess` gotchas guide](docs/htaccess-gotchas.md) covers the
rollback pattern in detail.

### Default TTL

Seconds to cache responses at the LSCache layer. Sent via the
`X-LiteSpeed-Cache-Control: public,max-age=N` header. Set to zero to
suppress the header and defer to any TTL emitted by Drupal core.

### Cache tag prefix

Optional. Prepended to every tag in the `X-LiteSpeed-Tag` header.
Useful when several Drupal sites share one LiteSpeed cache and you
want each site's invalidations scoped to its own tags. Typical value:
`site-a:` or `main:`.

### Private cache (authenticated users)

Public cache only holds responses that Drupal itself marks shareable.
Authenticated user pages, by default, are emitted with
`Cache-Control: private` and pass through to PHP on every request.

Enable **private cache** to hold a per-user copy of authenticated
responses in LSCache:

1. **Settings**: at *Configuration > Development > Performance > LSCache*,
   open the *Private cache (authenticated users)* section and tick
   *Enable private-cache mode*. The default trigger contexts (`user`,
   `user.permissions`, `user.roles`, `session`) cover the vast
   majority of authenticated Drupal pages without further tuning.
2. **`.htaccess`**: replace `CacheLookup public on` with
   `CacheLookup public on private on` inside the
   `<IfModule LiteSpeed>` block. The status report row will warn if
   this directive is missing while private mode is on.

Private cache scales storage with active users, so the default TTL is
shorter (10 minutes) than the public default. Tune to taste.

This module derives private-mode emission from Drupal's existing
cacheability metadata. A response carrying `user` (or
`user.permissions`, `session`, etc.) in its cache contexts is treated
as per-user variation; you do not need to mark fragments or routes
explicitly.

### Vary cookies (1.3.x and later)

Some public pages need to vary on something other than user identity:
mobile vs. desktop, currency, country, A/B test bucket. Configure the
relevant cookie names at *Configuration > Development > Performance >
LSCache* under the *Vary cookies* section, one per line.

For every cookie configured in the list, the module adds
`X-LiteSpeed-Vary: cookie=NAME` to every cacheable response. LSWS
then keys the cache entry on each named cookie's value, holding a
separate cache entry per value combination. Cookies that are absent
from a given request collapse to "no variance" cleanly.

Vary cookies stack on top of either public or private cache: each
mode's cache entry is keyed on the cookie value alongside its other
keys.

#### Required matching LSWS server-side directive

Emitting `X-LiteSpeed-Vary` from this module is necessary but not
sufficient. LSWS must also be told to honour the vary cookie via a
matching `CacheVary` directive at the server or vhost level (not in
`.htaccess`, since `CacheVary` is rejected there on most LSWS
distributions). Without this, the header is emitted correctly but
LSWS will not actually key its cache by cookie value, and repeat
requests with different cookie values may return the same cached
response.

Add a directive of the form:

```
CacheVary cookie=device_class,currency,country
```

to the LSWS configuration matching the cookies you have configured
in the module. Restart LSWS to pick up the change. After applying,
verify with:

```bash
curl -sI -b "device_class=mobile" "https://yoursite.example/" | grep -i x-litespeed-cache
curl -sI -b "device_class=desktop" "https://yoursite.example/" | grep -i x-litespeed-cache
curl -sI -b "device_class=mobile" "https://yoursite.example/" | grep -i x-litespeed-cache
```

Expected pattern: hit, miss (on switch to a new cookie value), hit
(on return to a previously-seen value).

### Purger Host header override (1.3.x and later)

LSWS keys its cache entries by the HTTP `Host` header. Real
traffic populates entries under your canonical site host (e.g.
`Host: www.example.com`). When the purger sends a PURGE request,
Guzzle defaults the Host header to whatever appears in the URL,
so a PURGE to `http://localhost/` carries `Host: localhost`. LSWS
evicts a phantom localhost cache bucket while the real
`www.example.com` entries stay live until their max-age expires.

The chronic symptom is "cache doesn't clear after node edits":
the purger returns HTTP 200 (PURGE accepted), invalidations
queue and drain cleanly, but editor saves still take the
configured max-age to propagate.

From 1.3.0-beta6 the purger supports an optional
**Host header override** at *Configuration > Development >
Performance > LSCache Purger*, exposed as the
`lscache_purger.settings:purge_host_header` config key. When set,
the outgoing PURGE request carries `Host: <value>` so LSWS
evicts entries under the canonical-host cache key:

```
drush config:set lscache_purger.settings purge_host_header "www.example.com" -y
drush cr
```

Enter a bare hostname, not a URL. The form validator rejects
values with a scheme or path.

Leave the override blank if your `purge_host` is a non-localhost
URL or if your LSWS install genuinely keys cache under localhost
(some containerised dev setups). The status report row
**LSCache purger Host header configuration** at
`/admin/reports/status` warns when the configuration looks
mismatched and offers a copy-pasteable suggestion.

#### Required matching server-side configuration

The Host header override only does its job when LSWS is actually
configured to accept PURGE requests on `purge_host`. The
companion `CacheLookup` directive in `.htaccess` (covered in the
**Critical: the .htaccess requirement** section above) and the
existence of the cache entries under the canonical host are
prerequisites. The status report row catches the common
mismatch shape; for deeper diagnostic notes see the
**PURGE Host header (LSWS keys cache by Host)** section of
`docs/htaccess-gotchas.md`.

### URL-based PURGE strategy (1.3.x and later)

Some LiteSpeed builds silently ignore the `X-LiteSpeed-Purge:
tag=NAME` directive: the PURGE request returns HTTP 200 but evicts
nothing, so editor content changes appear stuck until the cache
max-age expires. This was confirmed on LSWS Enterprise 6.3.4. The
only eviction signal those builds honour reliably is the PURGE
request's own URL.

From 1.3.0 the purger supports three strategies, set with
`lscache_purger.settings:purge_strategy`:

- `tag` (legacy): emit `X-LiteSpeed-Purge: tag=...`. Correct on
  builds where tag-PURGE works; byte-for-byte the legacy behaviour.
- `url`: resolve each invalidated cache tag to its canonical Drupal
  paths and send one PURGE per URL. Required on builds that ignore
  tag-PURGE.
- `auto` (default): safe by default. Resolves to `url`, which evicts on
  every LiteSpeed build, until a probe has positively confirmed that
  tag-PURGE works on this build, then upgrades to the more efficient
  `tag`. The probe runs automatically on cron, so a fresh install
  evicts correctly out of the box with no action, even on builds that
  silently ignore tag-PURGE, and upgrades itself to `tag` where it is
  available.

**Diagnose your LSWS with `drush lscache:diag`.** The command primes
a known URL, fires URL- and tag-scoped PURGEs, observes whether each
actually evicts (HIT vs MISS), records the verdict, and prints a
recommendation. With `auto` you do not normally need to run it: cron
auto-probes the first time the strategy is unprobed. Run it by hand to
probe immediately, or to re-probe after a server-config change:

```bash
drush lscache:diag
```

The status report at *Reports > Status report* says which strategy is
active and why (URL as the safe default pending the probe, URL because
tag-PURGE is ineffective here, or a warning only when `tag` is pinned
onto a build where tag-PURGE does not evict).

#### Evicting listing pages (views, blocks)

Aggregate cache tags such as `node_list` or `config:views.view.*`
have no single canonical URL, so the `url` strategy cannot map them
to a page directly. Two mechanisms cover this:

- **Tag-affinity table (automatic).** The module records which URLs
  emit which cache tags as cacheable responses are served, then
  consults that record when an aggregate tag is invalidated. After a
  few hours of normal traffic it knows that `node_list` should evict
  `/patch-notes`, `/blog`, and so on, with no configuration. The
  table self-populates and is pruned on cron; its size is shown on
  the status report.
- **Static URL map (cold-start).** For a guarantee that does not wait
  for affinity to warm (for example immediately after a deploy), pin
  listings explicitly in `lscache_purger.settings:static_url_map`:

```yaml
static_url_map:
  node_list:
    - /patch-notes
    - /blog
```

The resolver unions canonical, static-map, and affinity URLs, so a
single invalidation evicts the entity's own page plus every listing
that carries it.

Run `drush lscache:list-tag-coverage` (or check the **LSCache purger
listing coverage** status report row) to see which listing tags are
pinned, affinity-covered, or uncovered, with a suggested
`static_url_map` pin command for any gaps. Cron also auto-seeds Views
page listings into the affinity table as protected rows, so a
low-traffic Views listing still evicts even before a visitor warms
it.

### ESI fragments (1.2.x and later)

If most of a page is shareable but a small chunk varies per user
(welcome banner, cart count, notification badge), promote that chunk
to an ESI fragment so the surrounding page can stay in shared public
cache while LSCache holds the chunk per-user.

Render-array example:

```php
$build['cart_count'] = [
  '#type' => 'lscache_esi',
  '#callback' => 'my_module.cart:render',
  '#args' => [$user_id],
];
```

The element emits an `<esi:include src="/lscache-fragment/{token}" />`
tag in the rendered HTML. LiteSpeed Web Server, configured for ESI
processing, fetches the fragment, holds it in its private cache keyed
by user, and stitches it back into the surrounding response before it
reaches the browser.

`#callback` accepts the same form as `#lazy_builder`:
`service.name:methodName` or `Fully\\Qualified\\Class::staticMethod`.
Arguments must be JSON-primitive types (string, int, float, bool,
NULL).

**The target class must implement
`\Drupal\Core\Security\TrustedCallbackInterface`.** This is the same
policy Drupal core enforces on `#lazy_builder`. Any class that
provides ESI-fragment renderers should declare the interface
explicitly:

```php
use Drupal\Core\Security\TrustedCallbackInterface;

class CartRenderer implements TrustedCallbackInterface {
  public static function trustedCallbacks(): array {
    return ['renderCount'];
  }
  public function renderCount(int $user_id): array {
    // ...
  }
}
```

The fragment route rejects any token whose callback resolves to a
class without this declaration, even if the token signature is
otherwise valid. This is defence in depth: it bounds the impact of
a hash-salt compromise.

Fragments are signed with HMAC-SHA256 keyed on the site's hash salt,
so callers cannot enumerate or replay them. Every ESI fragment also
carries an `lscache_esi` cache tag, so invalidating that tag drains
every fragment site-wide.

LSWS must have ESI processing active for the virtual host. See
LiteSpeed's ESI documentation for the cache root config.

### Debug logging

Logs the tag payload on every cacheable response under the `lscache`
log channel. Helpful for setup verification; noisy in production.

## How tag invalidation works

1. A cacheable Drupal response leaves PHP with cache tags attached
   (for example `node:42`, `user:7`).
2. This module translates those tags into an `X-LiteSpeed-Tag` header
   on the response.
3. LiteSpeed stores the cached page indexed by the tags.
4. When Drupal invalidates a tag (a node edit, a config save), the
   `lscache_purger` submodule asks Purge to issue a tag-scoped
   invalidation request to LiteSpeed.
5. LiteSpeed drops every cached response tagged with that value.

The net effect: LiteSpeed serves Drupal's cacheable responses without
hitting PHP, while content changes still appear immediately.

## Relationship to the LiteSpeed-published module

LiteSpeed Technologies publishes a separate module at
[github.com/litespeedtech/lscache-drupal](https://github.com/litespeedtech/lscache-drupal).
That module is not on Drupal.org. This module is a clean-room
alternative designed around Drupal's native cache tag system and the
Purge framework, so it integrates with the rest of the Drupal contrib
caching ecosystem out of the box.

## Maintainers

- [Danielle Hallett](https://www.drupal.org/u/dinis)

Issues, patches, and merge requests welcome on the
[issue queue](https://www.drupal.org/project/issues/lscache).
