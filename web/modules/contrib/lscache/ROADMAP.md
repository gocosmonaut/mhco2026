# LSCache Roadmap

This is a living document. Milestones move between sections as work lands
and priorities shift. Suggestions and patches are welcome in the
[issue queue](https://www.drupal.org/project/issues/lscache).

## 1.0.0-alpha1 (shipped)

The scaffold release. Establishes the module structure, admin form, and
the foundational cache-tag header subscriber.

- Response subscriber emits `X-LiteSpeed-Tag` on cacheable responses.
- `X-LiteSpeed-Cache-Control: public,max-age=N` honoured when default TTL
  is configured.
- Admin form at **Configuration > Development > Performance > LSCache**.
- Optional site prefix on cache tags for multi-site deployments.
- `lscache_purger` submodule skeleton registered as a Purge plugin
  (actual invalidation lands in alpha2).

## 1.0.0-alpha2 (shipped)

Make `lscache_purger` functional, and fix the "LSWS returns miss on every
request" symptom observed during alpha1 field testing.

- HTTP purger issues `PURGE` requests with `X-LiteSpeed-Purge: <tags>`
  to the configured (or auto-detected) LiteSpeed host. Batches multiple
  tag invalidations into a single HTTP request.
- `hook_install()` auto-wires the minimum-viable Purge pipeline
  (purger + `core_tags_queuer` + `cron_processor`) so tag invalidations
  reach LiteSpeed without the operator running three drush commands.
- Failures log to the `lscache_purger` channel at WARNING severity with
  tag context and HTTP response code, so operators monitoring dblog
  can see invalidation gaps.
- Tag filter: `config:*`, `user:*`, `taxonomy_term*`, `*_view`, `*_list`,
  `http_response`, and `rendered` are dropped from the
  `X-LiteSpeed-Tag` header by default. Configurable but on by default.
  This matches the LiteSpeed-published module's behaviour and is
  required for LSWS to reliably cache responses on many environments.
- Per-install auto prefix (4-char hash of module directory) when no
  explicit `tag_prefix` is configured, so multi-site deployments
  don't collide on each other's tags.
- Response subscriber runs at priority `-999` (same as
  `lite_speed_cache`), after `FinishResponseSubscriber` has finalised
  `Cache-Control`, `ETag`, and `Vary`.
- Documentation: `php_flag output_buffering off` added to the required
  `.htaccess` block; without it LSWS misses cache-decision headers.

## 1.0.0-alpha3 (shipped)

Bug fix release driven by the alpha2 field test on TubeSpanner Portal,
plus operator UX wins.

- **Fix**: HTTP purger now resolves the LSCache host correctly in
  CLI/cron context (via RequestStack and `$base_url` fallbacks) and
  surfaces an actionable error message naming the config key when no
  host is available. Alpha2's purger always returned FAILED under
  cron-driven processing, which is the dominant operational pattern.
- **`hook_requirements()`**: the Drupal status report
  (`/admin/reports/status`) now inspects `.htaccess` and reports
  whether `CacheLookup public on` and `php_flag output_buffering off`
  are present. Missing CacheLookup is ERROR, missing output_buffering
  is WARNING. These two directives account for the majority of
  "module is enabled but nothing is cached" reports.
- **Settings form for the lscache_purger submodule** at
  `/admin/config/development/performance/lscache/purger`, exposing
  `purge_host` and `timeout` fields. Previously these were only
  settable via `drush config:set`.
- README documents that `purge_host` is required for CLI/cron-driven
  purging and how to verify the install via the status report.

## 1.0.0-alpha4 (shipped)

Two bug fixes from the alpha3 field test on TubeSpanner Portal, both
shippable together as a tightly-scoped patch release.

- **Fix**: status report `.htaccess` check no longer reports false
  ERROR when the directives are present. Alpha3 capped the read at
  the first 8KB of the file; the LSCache block lives past that
  offset whenever drupal-scaffold's `append` feature manages it
  (the recommended pattern). Now reads the full file.
- **Fix**: settings form copy and README updated to explain that
  CDNs (Cloudflare, Fastly, CloudFront, etc.) reject the PURGE
  method, so `purge_host` must point at an origin-direct URL like
  `http://127.0.0.1` or an internal hostname, not the public site
  URL. Alpha3's example of `https://your-site.example.com` failed
  silently for any CDN-fronted site.
- New "Test purge host" button on the settings form issues a real
  PURGE request to the entered URL and reports the result inline.
  Specifically detects Cloudflare's rejection signature (HTTP 400
  with `CF-Ray` header) and surfaces an actionable error message
  pointing at origin-direct alternatives.

## 1.0.0-alpha5 (shipped)

Operator UX work originally scoped for alpha3/alpha4 but deferred
twice to ship the field-driven fixes first.

- `everything` invalidation type support so `drush p:invalidate
  everything` maps to a site-wide LSCache flush.
- ESI block render element for edge-side-include support.
- Configurable tag filter list exposed in the admin form.
- Admin "Validate LSCache" diagnostic page (now largely covered by
  the alpha4 test purge button + alpha3 status report row, but
  still useful as a single page that runs all the checks).

## 1.0.0-beta1 (shipped)

Cut after alpha4 came back from field testing on TubeSpanner Portal
with no remaining bugs and a clean end-to-end invalidation cycle.
Same code as alpha4; the version bump signals API freeze and asks
operators to soak the module on production-shape sites.

## 1.0.0-beta2 (shipped)

Bug fix release. Field testing of beta1 surfaced a security-relevant
correctness bug worth shipping immediately rather than batching with
the deferred operator UX items below.

- **Fix**: response subscriber now defers to Drupal's HTTP cacheability
  decision (`Response::isCacheable()`) before emitting LSCache
  headers. Previously, admin pages and other responses Drupal had
  marked uncacheable (`Cache-Control: private`, `no-cache`, or
  `no-store`) still received `X-LiteSpeed-Cache-Control:
  public,max-age=N`, lying to LSWS about cacheability. LSWS happens
  to bypass on cookie / Cache-Control: private, so no actual cache
  poisoning occurred, but the latent risk was real: a future LSWS
  config change could have caused authenticated admin responses to
  cache for the configured TTL with `user:N` tags.
- New unit tests cover the cacheable / private / no-cache / no-store
  paths in the subscriber.

## 1.0.0-rc1 (shipped)

Cut after beta2 to signal API freeze and invite operators to soak the
module in production ahead of the 1.0.0 cut. Same code as beta2; only
a cosmetic info.yml description polish landed during the soak window.

## 1.0.0 (shipped)

Cut after rc1 soaked cleanly across TubeSpanner Portal and several
live production sites without tag-invalidation regressions, header
correctness issues, or open queue reports. 1.0.0 signals API
stability and opens the project to Drupal Security Advisory coverage.
Subsequent 1.0.x releases are reserved for bug fixes and security
patches; new feature work moves to the 1.1.x track.

The deferred operator UX items (everything-invalidation, configurable
tag filter list, ESI render element, single-page diagnostics) carry
over to 1.1.x along with the ESI work below.

## 1.0.1 (shipped)

Soft-hardening patch identified during the 1.0.0 pre-stable security
review.

- **Hardening**: the `purge_host` form field now validates the URL
  scheme, accepting only `http://` and `https://`. Drupal's url
  element type otherwise accepts any scheme PHP's `filter_var`
  recognises (`file`, `ftp`, `ldap`, etc.). The change is operator
  facing only: only an admin with `administer lscache` could set
  the value, and the HTTP purger would have failed at runtime
  anyway, but the new validator surfaces an immediate error and
  closes a (very narrow) avenue for an admin to use the form to
  probe internal endpoints.
- README updated to call out the http/https scheme requirement.

## 1.1.0-beta1 (shipped)

Cut after alpha2 came back from a clean re-test on portal-dev with
both alpha1 findings verified fixed and zero regressions. Same code
as alpha2 (commit 1322537); the version bump signals API freeze
and asks operators to soak the module in production-shape sites
ahead of rc1.

## 1.1.0-alpha2 (shipped)

Two bug fixes from the alpha1 field test on portal-dev. The
cache-poisoning canary (3 cycles × 3 page shapes × 2 users = 18
trials) passed cleanly, validating the architecture; what surfaced
was scope-creep on the private-cache emission rather than a
correctness flaw.

- **Fix**: admin routes no longer emit any LSCache header. Alpha1's
  decision tree only checked `Cache-Control: no-store` to disqualify
  responses, but admin pages return `must-revalidate, no-cache,
  private` (same as authenticated content) and so were being held in
  private cache for the full TTL. Practical impact was bounded by
  per-user keying (no cross-user leakage) but settings forms looked
  stale to operators for up to 600s after a change, and the LSCache
  settings page itself was caching. Switched to `\Drupal\Core\Routing
  \AdminContext::isAdminRoute()` for the skip, which covers everything
  under `/admin/*` plus contrib routes flagged `_admin_route: TRUE`.
- **Fix**: debug log messages now contain resolved values rather than
  literal `@tags` / `@ttl` placeholder text. Drupal's dblog UI
  substitutes the placeholders correctly, but `drush watchdog:show`
  doesn't run the same pipeline; alpha1 used the deferred-substitution
  pattern, alpha2 uses inline `sprintf` strings so every consumer sees
  resolved values.

## 1.1.0-alpha1 (shipped)

Private-cache mode for authenticated users. The minor-version order in
this roadmap was swapped from earlier drafts: 1.1 now ships private
cache and 1.2 ships ESI, because an ESI fragment is structurally a
private-cached response at its own URL, so building 1.1 first means 1.2
plugs into stable infrastructure rather than throwaway scaffolding.

Drupal-native angle: derive the cache mode from existing cacheability
metadata. A response with cache contexts containing `user`,
`user.permissions`, `user.roles`, or `session` (or any operator-
configured trigger) automatically gets `X-LiteSpeed-Cache-Control:
private,max-age=N` instead of being passed through to PHP. Operators
do not hand-tag fragments; Drupal already tells us.

- Response subscriber detects per-user cache contexts and emits
  private-mode headers.
- Settings: enable toggle (off by default), private TTL, configurable
  trigger-context list with namespace-prefix matching.
- `hook_requirements()` warns if private mode is enabled but
  `.htaccess` lacks `CacheLookup ... private on`.
- `hook_update_8101()` populates private-cache defaults for sites
  upgrading from 1.0.x.
- Unit tests cover public/private decision branches, namespace
  matching, no-store rejection, and back-compat with private mode off.

## 1.2.0-alpha3 (shipped)

Critical bug fix from the 1.3.0-alpha2 adopter field test on
portal-dev (the 1.3.x adopter test exercises 1.2's ESI surface
because 1.3.x cumulatively contains 1.2's work).

- **Fix**: ESI render element now wraps its `#markup` output in
  `\Drupal\Core\Render\Markup` so Drupal's renderer treats the
  emitted `<esi:include>` tag as already-trusted output. Without
  the wrap, Drupal pipes `#markup` strings through
  `Xss::filterAdmin()`, which only allows a fixed set of HTML
  tags; `<esi:include>` is not on that list, so the entire string
  was being filtered to empty. ESI fragments produced no output
  at all in 1.2.0-alpha2. Same wrap applied to the missing-
  callback fallback marker, which was equivalently affected.

## 1.2.0-alpha2 (shipped)

Forward-port of the 1.1.0-alpha2 fixes (admin-route skip,
log-placeholder substitution) onto the ESI-track baseline. No new
ESI work in this alpha; same render element and fragment route as
1.2.0-alpha1, plus the response-subscriber correctness fixes carried
forward via merge of 1.1.x. First public alpha of the 1.2.x track:
1.2.0-alpha1 was tagged but never published as a release-node, since
the alpha1 field-test loop ran on 1.1.x and surfaced bugs that
needed to land before any 1.2 alpha hit operators.

## 1.2.0-alpha1 (superseded by 1.2.0-alpha2)

ESI (Edge Side Includes) on top of 1.1's private-cache machinery.
Cache the bulk of an authenticated page publicly while LSCache holds
per-user fragments out of the shared cache.

Drupal-native angle: a `#type: 'lscache_esi'` render element accepts
the same `#callback` and `#args` shape as `#lazy_builder`. Instead of
rendering the inner content inline (or via BigPipe streaming), it
emits an `<esi:include src="..." />` tag pointing at the LSCache
fragment route. The surrounding response stays in shared public cache
because the per-user variation is pushed out to the fragment.

- New render element `lscache_esi` mirrors `#lazy_builder`'s contract.
- New route `/lscache-fragment/{token}` resolves the token to a
  callable and invokes it, returning a private-cacheable response.
  The 1.1 response subscriber adds the LSCache private-mode header.
- HMAC-SHA256 token signing keyed on the site's hash salt prevents
  enumeration or replay of fragments.
- Fragments carry an `lscache_esi` cache tag so a single tag
  invalidation can purge every ESI fragment site-wide.

## 1.3.3 (in progress)

Operator-experience polish: a calmer status report and a steadier
diag probe. No behaviour change to caching or invalidation.

- **Status report de-clutter.** The LSCache rows now stay quiet when
  healthy. The "listing coverage" row no longer dumps a full
  drush config:set command per uncovered listing inline; it shows the
  count and the affected tag names (capped) and points at
  drush lscache:list-tag-coverage for the per-tag pins. The scheme,
  invalidation-strategy (once probed), tag-affinity-table (below its
  soft cap), Host-header, and vary-cookie rows now return nothing when
  there is nothing to act on, so a correctly configured site shows
  two LSCache rows (the .htaccess gate and the purger config anchor)
  instead of seven. Problems and the one-time "run diag" nudge still
  surface.
- **drush lscache:diag warm-up.** The first probe immediately after
  drush cr could report URL-PURGE INEFFECTIVE as a warm-up artifact
  (the first PURGE landing before LSWS had settled), then read WORKS
  on every rerun. The URL-PURGE check now retries once, so the
  artifact resolves while a genuinely ineffective build still reads
  INEFFECTIVE.

Deferred to 1.4.x (features, not polish): post-purge sentinel
verification, the everything invalidation type, a static_url_map
discovery helper, and kernel coverage for the new status rows and
the diag command.

## 1.3.2 (shipped)

Critical fix: restore the Drush command service arguments so drush
works on consuming sites.

1.3.0 and 1.3.1 dropped the arguments from
modules/lscache_purger/drush.services.yml on the theory that the
static create() factory plus the CLI command attributes were the
single DI path. That is false for this layout: the command classes
live in src/Commands/, not src/Drush/Commands/, so Drush discovers
them only via the services file, and on Drush 12/13 the
LegacyServiceInstantiator builds them with newInstanceArgs() and
never calls create(). With no arguments, new LscacheDiagCommands()
ran with 0 args and threw ArgumentCountError during command
discovery in bootstrapDrupalFull(), breaking every drush command
(status, cr, updb, cim) and aborting deploys into maintenance mode.
Found when the portal tried to upgrade from the pre-release patch
(which had the correct wiring) to 1.3.1 on Drupal 11.3 / Drush 13.

- **Fix**: restore the arguments blocks for both command services,
  with service IDs matching each class's create(). A comment records
  why the arguments are required so the wiring cannot be removed
  again. The earlier double-wiring concern was wrong for this
  layout: create() is never on the discovery path.

Operators on 1.3.0 or 1.3.1 with lscache_purger enabled should
upgrade to 1.3.2; drush is unusable on those two releases under
Drush 12/13. A later release may relocate the commands to
src/Drush/Commands/ for modern attribute discovery, deferred here to
keep the hotfix minimal.

## 1.3.1 (shipped)

Cache-correctness fix for private-cache mode: never full-page-cache
an authenticated response that embeds per-session client-side state.

Found on portal dev (2026-06-04): with CacheLookup public on private
on, LSWS stored and served a full-page private cache for
authenticated users. The cached HTML carried a drupalSettings /
BigPipe payload generated per request (ajaxTrustedUrl, ajaxPageState
theme token, CSRF and form-build tokens, BigPipe placeholder ids).
Replayed to a different request, core JS reading those keys (ajax.js
drupalSettings.ajaxTrustedUrl, escapeAdmin.js, active-link.js,
big_pipe.js) threw, so every use-ajax link, dialog, AJAX-exposed
View filter, and contextual link silently broke for logged-in users
sitewide. Same family as the beta3 anonymous-to-admin bug.

- **Fix**: the response subscriber now detects the drupalSettings
  JSON block in the body and suppresses private emission for it,
  emitting X-LiteSpeed-Cache-Control: no-cache (the same active
  suppression the BigPipe guard uses). Because effectively every
  authenticated HTML page carries that block, the practical effect
  is that private mode no longer full-page-caches authenticated
  HTML, which is the safe default (plain Drupal does not cache
  authenticated pages either). Private mode still applies to
  per-user responses with no embedded JS state.
- **Tests**: two cases in LscacheResponseSubscriberTest cover the
  suppression (drupalSettings present) and the negative path (no JS
  state, private still emits).

Operators who enabled CacheLookup private on for 1.1.x private cache
can keep it on safely once on 1.3.1; the module no longer emits an
unsafe private store. Sites that prefer the server-side switch can
set private off (plain Drupal's default) with no real loss on
anonymous-heavy traffic.

## 1.3.0 (stable)

Per-URL PURGE strategy, self-diagnostic, and tag-affinity eviction,
from a five-layer field diagnosis on the portal LSWS Enterprise
6.3.4 deployment (UKHost4U/Jelastic). beta6 fixed the Host-header
cache-key bug, but the reporter then found the eviction mechanism
itself was silently ineffective on this LSWS build: tag-based PURGE
returns HTTP 200 and evicts nothing. The chronic "edits don't clear
cache" class was therefore only half-closed by beta6.

The methodology that surfaced all of this: developing and testing
against the live LSWS for immediate HIT/MISS feedback. None of the
layers below reproduce in a unit test or a generic OpenLiteSpeed
dev box; they are specific to how this LSWS Enterprise build handles
PURGE. The new `drush lscache:diag` command bottles that feedback
loop so any operator can characterise their own LSWS in one command.

Five layers found, each invisible to unit tests:

- `X-LiteSpeed-Purge: tag=NAME` is silently ignored; PURGE is
  URL-scoped only on this build.
- `X-LiteSpeed-Purge: url=PATH` is ALSO ignored; the only eviction
  signal LSWS honours is the PURGE request's own URL, so the purger
  must send one PURGE per URL.
- LSWS keys cache by request scheme: an http purge_host evicts the
  empty http bucket while real https traffic stays cached until TTL.
- LSWS on loopback presents a cert valid for the canonical host,
  not 127.0.0.1, so Guzzle's default verify rejects loopback https.
- The affinity recorder first read the tag_filter-stripped
  X-LiteSpeed-Tag header, so it missed node_list and never evicted
  listings when NEW content was published into them.

Deliverables:

- **Per-URL PURGE strategy.** New `purge_strategy: auto|tag|url`
  (default `auto`). `url` resolves each cache tag to canonical Drupal
  paths via the new InvalidationUrlResolver and sends one PURGE per
  URL; `tag` is the byte-for-byte legacy path (regression-safe);
  `auto` reads the diag probe result from state and self-selects.
  Loopback purge_host values skip TLS verify (no MITM surface; the
  connection never leaves the host).
- **`lscache:diag` drush command.** Primes a URL, fires URL- and
  tag-scoped PURGEs, observes real HIT/MISS eviction, persists the
  verdict to state, and prints a recommendation. Drives `auto` and
  a status report row.
- **Tag-affinity table.** Records which URLs emit which tags from
  cacheable responses, so aggregate-tag invalidations (node_list,
  view configs) resolve to the listing URLs that carry them.
  Self-populating from traffic; cron-pruned by 2x the longest TTL;
  status report row for table size. The recorder reads the unfiltered
  cacheable metadata (not the stripped header) so publishing new
  content evicts listings.
- **Static URL map.** `static_url_map` pins aggregate tags to known
  listing URLs (e.g. node_list -> /patch-notes) for a cold-start
  guarantee before affinity has warmed. Empty by default.
- **Status report rows.** Scheme-mismatch detection and active-
  strategy/probe-result rows, both with copy-pasteable drush
  remediation; CLI false-positive suppression on the scheme check.
- **Controller-listing coverage (post-rc field findings).** Three
  follow-on fixes from the portal-stage /help diagnosis and the
  production rollout: (1) the response subscriber now emits
  X-LiteSpeed-Cache-Control for any LSWS-cacheable response even
  when the filtered tag set is empty, bringing controller-rendered
  listings (e.g. /help, whose tags are all aggregate) under the
  module's contract so the per-URL PURGE evicts them; the recorder
  gates on Cache-Control and also captures bundle-specific
  `*_list:bundle` tags. (2) A `drush lscache:list-tag-coverage`
  command plus a matching status report row (backed by a
  ListTagCoverage service) report which listing tags are pinned,
  affinity-covered, or uncovered. (3) The affinity table gains a
  `protected` column (update_8106): cron auto-seeds Views page
  listings as protected rows that prune-by-age never removes,
  closing the low-traffic listing gap; the recorder's hot path
  omits the column so it is safe in the pre-updb upgrade window.

Production-validated on portal LIVE (www.tubespanner.com, LSWS
Enterprise 6.3.4, deploy live-2026-06-06-86cff1b): both `/` and the
controller-rendered `/help` listing healthy, `lscache:diag` reports
URL-PURGE WORKS / tag-PURGE INEFFECTIVE / auto->url, and publishing
a node via REST auto-evicts its listing (MISS) with no manual
purge. Soaked under heavy production traffic across dev, staging,
and live before this cut, which is why 1.3.0 ships stable directly
rather than through a further rc soak.

CI-hardening folded into the cut (no behaviour change): dropped
`final` on the two unit-mocked service classes, removed the
phpstan-phpunit-incompatible method-level @covers annotations, kept
single-line docblock short descriptions, and extended the cspell
dictionary. All seven CI jobs green including the advisory
allow_failure jobs, matching the beta6 baseline.

Deferred to 1.3.1 / 1.4.x (unchanged): post-purge sentinel
verification flag, `everything` invalidation type, a static_url_map
discovery helper, kernel coverage for the new status rows and the
diag command, and operator-experience polish.

## 1.3.0-beta6 (shipped)

Third cache-correctness fix from continued 1.3.0-beta5 field
testing on portal-master. The reporter diagnosed chronic
"cache doesn't clear after node edits" complaints from editors
that had been silently degrading the rc1 and rc2 soak windows all
along.

Root cause was on the purger side: Guzzle defaults the HTTP
`Host` header from the request URL. With `purge_host` set to
`http://localhost/` per the module's own form guidance for
single-host installs, the outgoing PURGE carried `Host:
localhost`. But LSWS keys cache entries by Host, and real traffic
populates entries under `Host: www.tubespanner.com`. PURGE was
evicting a phantom localhost bucket while the real entries stayed
live until their 24h max-age expired.

The reporter shipped a draft patch with curl reproduction
confirming the diagnosis end-to-end. beta6 lands the patch plus
the obvious follow-ups, in the same shape as the beta5 cycle.

- **Fix**: purger now reads a new `purge_host_header` config key.
  When set, the outgoing PURGE request overrides the `Host`
  header so LSWS evicts entries cached under the canonical site
  host rather than the localhost bucket Guzzle would derive from
  the URL. Default unset preserves backward compatibility for
  edge-case installs where LSWS genuinely keys cache under
  localhost (some containerised dev setups).
- **Settings form**: new text field at *Configuration &rsaquo;
  Development &rsaquo; Performance &rsaquo; LSCache Purger* with
  help text and a collapsed details panel explaining the Guzzle-
  derives-Host-from-URL behaviour and the LSWS-keys-by-Host
  consequence. Element validator rejects scheme + path values.
  The "Test purge host" diagnostic mirrors the runtime PURGE so
  operators don't get false-OK signals from localhost-only tests.
- **`hook_requirements()`**: new status report row **LSCache
  purger Host header configuration** warns when purge_host
  targets localhost, purge_host_header is unset, and the
  canonical site host is non-localhost. Includes a copy-pasteable
  drush command with the suggested value pre-filled. Catches the
  bug class at install time rather than waiting on editor
  complaints.
- **Tests**: three new test cases in LscachePurgerTest using a
  real Guzzle client with mock handler + history middleware.
  Assert the outgoing PURGE's Host header reflects
  purge_host_header when set, falls through to Guzzle default
  when unset, and treats whitespace-only values as unset.
- **Logger context**: both WARNING log lines in `invalidate()`
  now name the effective Host header alongside the URL.
  Operators reading dblog can diagnose the same bug class on a
  future site in seconds rather than reproducing the Guzzle
  URL-derived-Host behaviour cold.
- **lateruntime processor auto-wire**: `purge_processor_lateruntime`
  added to lscache_purger's info.yml dependencies so the queue
  drains at the end of each Drupal request rather than only on
  cron runs. New `lscache_purger_update_8102` installs the
  module on existing 1.3.x sites. Closes the "purger works,
  queue grows, nothing drains in real time" class of report.
- **Docs**: new section in `docs/htaccess-gotchas.md` on the
  PURGE Host header keying. Includes the curl reproduction,
  the form-field guidance, and the status report row reference.

Pure correctness fix; no API contract changes. Same back-to-beta
convention as beta3, beta4, and beta5: a meaningful
cache-correctness fix landing during the rc window resets the
soak counter before re-claiming rc quality.

Soak-window honesty note: the beta5 "weekend soak so far so
good" signal was specifically about the vary-cookie scenario (b)
and BigPipe scenario (a) fixes, and those code paths are
independent of the purger. The "cache invalidation works
end-to-end on portal-master" claim was never something we should
have been making during the rc cycle, because this Host header
bug was silently breaking it all along. Future soak signals
should be scoped to the specific fix under test, not to
"caching works."

Deferred to 1.3.1 / 1.4.x (unchanged): post-purge sentinel
verification flag, `everything` invalidation type, and the
operator-experience polish items.

## 1.3.0-beta5 (shipped)

Second cache-correctness fix from the 1.3.0-beta4 retest on
portal-master. The reporter ran the amended canary protocol (admin
+ editor + anon) and confirmed beta4's BigPipe suppression resolved
scenario (a). A second leakage pattern surfaced: even with BigPipe
suppressed, the homepage cache was being keyed identically for
anonymous and authenticated requests, so whichever variant
populated the cache first was served to everyone. Root cause was
on the cache-key side: with private-cache mode on, the module
emitted `X-LiteSpeed-Cache-Control: private` but did not tell LSWS
to vary the cache entry on the session cookie. LSWS hashed both
variants to the same key.

The reporter shipped a patch and a 27-request canary confirming
the fix. beta5 lands the patch plus the obvious follow-ups.

- **Fix**: response subscriber now auto-appends the session cookie
  name to the `X-LiteSpeed-Vary` header whenever
  `private_cache.enabled = TRUE`. The cookie name is discovered
  via Drupal core's `SessionConfiguration` service, so the fix
  works without any new config keys and respects site-specific
  cookie names (`SSESS_<hash>`). Operator-configured vary cookies
  in `lscache.settings:vary_cookies` are preserved; the session
  cookie is appended to that list and deduplicated.
- **Tests**: three new test cases in LscacheResponseSubscriberTest
  cover the auto-append in private mode, the no-append path in
  public-only mode, and the deduplication path when the session
  cookie is already in the operator's vary list.
- **`hook_requirements()`**: new status report row at *Reports
  &rsaquo; Status report* parses `CacheVary cookie=...` directives
  out of `.htaccess` and compares them with the cookies the
  subscriber is emitting. Disagreement renders a WARNING with a
  copy-pasteable suggested directive. This catches the
  module-emits-header-but-LSWS-isn't-told pattern at install time
  rather than during canary testing.
- **Docs**: new section in `docs/htaccess-gotchas.md` on
  authenticated cache differentiation. Explains the scenario (b)
  bug class, beta5's auto-vary fix, and the matching
  `CacheVary cookie=` directive operators must add server-side.
  Includes a drush ev one-liner to discover the session cookie
  name and an example `<IfModule LiteSpeed>` block combining the
  CacheLookup, output_buffering, and CacheVary directives.

Pure cache-correctness fix; no API contract changes. Same
back-to-beta convention as beta3 and beta4: a meaningful
cache-correctness fix landing during the rc window resets the
soak counter before re-claiming rc quality.

Deferred to 1.3.1 / 1.4.x (unchanged from beta4 list): post-purge
sentinel verification flag, `everything` invalidation type, and
the operator-experience polish items.

## 1.3.0-beta4 (shipped)

Cache-correctness fix from the 1.3.0-beta3 retest on portal-master.
The retest reporter corrected an earlier miscall on Finding 7 (the
auth-served-as-anonymous variant from the rc1 hand-back): the
original canary protocol used authenticated-role-only test users
with no visible per-user markup beyond what BigPipe streams,
making the leak-detector structurally incapable of distinguishing
"served correct authenticated variant" from "served cached
anonymous variant." The same operator hitting the canonical
homepage as admin saw the anonymous-shape body; hitting a
cache-busted URL produced the admin-shape body. This is the
scenario (b) outcome: a real cache-correctness gap, not a
downstream artifact of Finding 1.

Root cause: BigPipe resolves its placeholders at streaming time
inside PHP. LSWS serves cached responses without invoking PHP, so
BigPipe never runs on cache hits and the placeholders stay
unresolved. Authenticated content rendered via BigPipe
(admin toolbar, user-account widgets, `#lazy_builder` outputs)
goes missing from cached responses.

- **Fix**: response subscriber now scans the response body for
  `data-big-pipe-placeholder-id` markers. When detected, emits
  `X-LiteSpeed-Cache-Control: no-cache` to actively suppress
  LSWS-side caching for that response. Active suppression is
  necessary because the HTTP `Cache-Control` header is still
  `public,max-age=N` (the response is HTTP-cacheable in the
  abstract); a soft skip would let LSWS cache via its own header
  parsing.
- **Tests**: three new test cases in LscacheResponseSubscriberTest
  cover BigPipe suppression in both the public and private
  emission branches, plus a regression guard verifying that
  non-BigPipe responses continue to cache normally.
- **Docs**: new section in `docs/htaccess-gotchas.md` on the
  BigPipe interaction. Explains the streaming-vs-static
  incompatibility, documents the automatic suppression behaviour,
  and points operators with per-user content needs at the
  `#type: 'lscache_esi'` migration path (ESI fragments resolve
  per-user at the LSWS layer instead of per-user at PHP-streaming
  time).
- **Class docblock** on LscacheResponseSubscriber updated to
  record the BigPipe exclusion alongside the existing admin-route
  exclusion. Field-test attribution preserved so future readers
  can trace where the constraint comes from.

Pure cache-correctness fix; no API contract changes. The
`HTTP 200 = SUCCEEDED` mapping in the purger is unchanged.
Operators who hit this on beta3 can re-enable LSCache once on
beta4 and re-run the amended canary protocol (admin role +
editor role + anon, checking for toolbar markup and BigPipe
placeholder resolution explicitly, not just username markers).

Deferred to 1.3.1 / 1.4.x (unchanged from beta3 list): post-purge
sentinel verification flag, `everything` invalidation type, and
the operator-experience polish items.

## 1.3.0-beta3 (shipped)

Operator-experience fixes from the 1.3.0-rc2 production-soak
handback on portal-master. The rc2 install reached the actual PURGE
code path for the first time on a Cloudflare-fronted Jelastic-
managed LSWS Enterprise environment, surfacing pre-existing
limitations that earlier soak runs hadn't exercised. Stepping back
from the rc convention to beta because the rc2 experience on this
environment was meaningfully worse than rc1's; resetting the soak
window before re-claiming rc quality.

- **Fix**: actionable error message on the no-host failure path.
  Suggested example URL in the failure reason changed from
  `https://your-site.example.com` (which sends operators behind
  any CDN into a 400 trap) to `http://localhost/`, with explicit
  guidance that the value must be the local LSWS origin, not the
  public CDN-fronted URL. Same change on the settings form
  description and placeholder.
- **Fix**: actionable error messages on HTTP non-2xx purge
  failures. The new `formatNon2xxReason()` helper detects
  Cloudflare's HTTP 400 + CF-Ray response signature (mirroring the
  detection the Test purge host button has had since 1.0.0-alpha4)
  and surfaces a CDN-specific message pointing the operator at
  origin-direct alternatives. Other 4xx and 5xx responses get
  reasonable generic reasons that include the response Server
  header for intermediary identification.
- **Fix**: purger logging now routes through the
  `lscache_purger` channel via injected
  `@logger.channel.lscache_purger`. The channel was registered in
  services.yml since 1.0.0 but never used; `drush watchdog:show
  --type=lscache_purger` previously returned "Unrecognized message
  type" because the plugin used PurgerBase's default framework
  channel. Operators looking for purge issues by channel name now
  see them.
- **Fix**: messenger-surfaced failure reasons now apply to every
  failure path (no-host, HTTP non-2xx, transport error), not just
  the no-host case rc2 covered. drush invocations show the cause
  inline.
- **Docs**: new section in docs/htaccess-gotchas.md on LSWS-side
  PURGE handling. Makes explicit that HTTP 200 from LSWS means
  "request accepted at the protocol level," not "entry evicted."
  On managed-LSWS environments and on Enterprise installs with
  admin-port routing, the two can be distinct states. Documents
  the diagnostic (Last-Modified before/after), the LSWS
  configuration requirements, and operator workarounds when
  tag-PURGE doesn't actually take effect.
- **Docs**: new section on LSCache vs Drupal's
  `x-drupal-dynamic-cache: UNCACHEABLE` precedence. LSCache follows
  HTTP `Cache-Control`, not Drupal's separate cacheability hint;
  documents how to opt out via response policy or
  `#cache.max-age = 0` when those disagree.

Deferred to 1.3.1 / 1.4.x: post-purge sentinel verification (new
config flag for environments where 200 means "accepted" but not
"evicted"), `everything` invalidation type (operator escape hatch
for environments where tag PURGE doesn't take effect), and the
operator-experience polish items already deferred (status-report
directives summary, vary_cookies recent-traffic validation, demo
ESI submodule + block-form ESI checkbox + `drush lscache:esi:list`).

Finding 7 (auth-served-as-anon variant from the rc1 hand-back)
canary clean on rc2: 81-request controlled test across 3 paths x
9 cycles x (A, B, anon) cookie states with zero cross-bucket
leakage. Strongly supports the "downstream artifact of Finding 1"
hypothesis; full closure pending LSWS-side PURGE working in the
test environment so the invalidation-driven re-test can run.

## 1.3.0-rc2 (shipped)

Bug-fix release candidate that ran into a CI-feedback loop and a
production-soak finding. Code-wise it carried the defensive install
hook for `lscache_purger.settings`, the status report row for the
purger configuration, README clarification on what
`enabled = false` actually controls, and regression tests for the
missing-host purge gap. Three CI fix-up rounds addressed PHPStan,
PHPCS, CSpell, and PHPUnit feedback before the tag pushed.

The production soak then surfaced operator-experience gaps that
warranted a step back to beta3 rather than another rc cycle.

## 1.3.0-rc1 (shipped)

API freeze cut after the 1.3.0-beta2 cumulative soak. Carried the
pre-rc1 audit hardening: TrustedCallbackInterface enforcement on
ESI fragment callbacks, coding-standards fixes, doc URL refresh,
and three new unit-test classes for the ESI element, fragment
controller, and install-hook flattener. Superseded by 1.3.0-rc2
when the production soak surfaced the silent-failure-on-missing-
purger-config bug.

## 1.3.0-beta2 (shipped)

Two bug fixes from the 1.3.0-beta1 production-soak handback on
portal-dev. The soak halted at Day 0: neither bug ever let the
soak start collecting traffic data.

- **Fix (CRITICAL)**: settings form no longer crashes with TypeError
  when active config is missing keys. The `ConfigTarget` closures
  for `private_cache.contexts` and `vary_cookies` declared strict
  `array` parameter types; `Config::get()` returns NULL when a key
  is absent, which threw inside the closure and took out the entire
  settings page. Both closures now declare `?array` and fall back
  to an empty list. Operators in this state previously had no signal
  the form was broken (the status report row stayed green) until
  they tried to open the form. Fix is defensive even after the
  backfill hook below populates missing keys.
- **Fix (HIGH)**: new `lscache_update_8103` walks the install YAML
  and writes any missing default keys to active config without
  overwriting operator changes. Sites that bumped through versions
  with manual `drush config:set` cycles could end up with partial
  config (siblings dropped when an entire mapping was replaced);
  the form's strict closures crashed on any such drift. The hook
  is generic; it backfills any missing key from
  `config/install/lscache.settings.yml`, so future schema additions
  pick up the same backfill behaviour automatically.
- Branch-consolidation: from 1.3.0-beta2 forward, all LSCache
  development happens on the `1.3.x` branch. The `1.1.x` and
  `1.2.x` branches are frozen at their respective `beta1` tags
  (which contain the form-WSOD bug). Operators previously installed
  on `1.1.0-beta1` or `1.2.0-beta1` should bump to `1.3.0-beta2` to
  pick up the fixes; the cumulative branch model keeps every prior
  feature (private cache, ESI) available alongside the 1.3
  vary-cookie additions. Maintaining three concurrent beta tracks
  was paying a forward-port cost without delivering operator value
  while the project is still shaking out alphas/betas.

Operator-experience improvements deferred from the field-test
report (status report exposes required directives explicitly,
vary_cookies form validates entries against recent traffic, demo
ESI submodule + block-form ESI checkbox + `drush lscache:esi:list`)
all carry over to the 1.3.0-rc1 / 1.3.0-stable polish window or
the 1.4.x track.

Carry-over from 1.3.0-alpha3 still parked: post-PURGE re-cache
flake on responses without ESI markup. Soak halt prevented Day 7 /
Day 14 checkpoint data; the resumed beta2 soak is the next chance
to either confirm the pattern or rule it out as noise.

## 1.3.0-beta1 (shipped)

Cut after alpha3 came back from a clean re-test on portal-dev with
both alpha2 bugs verified fixed. ESI fragments render and invalidate
correctly via cache tags, vary cookies emit on every cacheable
response, no regressions on the inherited 1.1 admin-route +
cache-poisoning checks.

Same code as alpha3 plus a README addition documenting the matching
LSWS server-side `CacheVary cookie=NAME` directive that's required
for the vary-cookie header to actually key the cache. The reporter
caught this as a doc-level gap during the re-test (header emitted
correctly, but LSWS didn't honour it without the server-side hint).
Not a module bug, just a doc-level operator-experience improvement.

Known issue flagged for further investigation, not blocking beta1:
post-PURGE re-cache flake on responses without ESI markup, observed
by the adopter during alpha3 re-test. Could not be isolated; may be
unrelated noise from concurrent purge cron, or an LSWS-side
interaction with response-header shape. Not new in alpha3.

## 1.3.0-alpha3 (shipped)

Two bug fixes from the 1.3.0-alpha2 adopter field test on portal-dev.

- **Fix (forward-port from 1.2.0-alpha3)**: ESI render element wraps
  its `#markup` output in `\Drupal\Core\Render\Markup` so Drupal's
  renderer treats the emitted `<esi:include>` tag as already-trusted.
  Without the wrap, `Xss::filterAdmin` stripped the tag entirely and
  ESI fragments produced no output. See 1.2.0-alpha3 entry above for
  full context.
- **Fix**: vary-cookie emission no longer requires the response to
  declare a matching `cookies:NAME` cache context. Alpha2's logic
  intersected the configured cookie list with the response's cache
  contexts, which silently dropped the header on any response whose
  render code didn't happen to declare the context, meaning the
  feature was inert for most installs. Alpha3 emits
  `X-LiteSpeed-Vary: cookie=A,B,C` for every configured cookie on
  every cacheable response. LSWS handles cookies that are absent
  from a given request as "no variance" cleanly. Operator's config
  is now the directive, not a filter against Drupal-side metadata.
- Existing vary-cookie unit tests updated to match the new always-
  emit behaviour. The cache-context-required behaviour was an
  over-specification that ran counter to operator expectation.

## 1.3.0-alpha2 (shipped)

Forward-port of the 1.1.0-alpha2 fixes (admin-route skip,
log-placeholder substitution) onto the vary-cookie baseline,
delivered via merge of 1.2.x (which itself merged 1.1.x). No new
vary-cookie work in this alpha; same response-subscriber additions
and config schema as 1.3.0-alpha1, plus the upstream correctness
fixes. First public alpha of the 1.3.x track: 1.3.0-alpha1 was
implemented and CI-validated but never tagged or released, since
the alpha1 field-test loop ran on 1.1.x first and surfaced bugs
that needed to land before any 1.3 alpha hit operators.

## 1.3.0-alpha1 (superseded by 1.3.0-alpha2)

Named vary cookies for mobile detection, currency, country, A/B tests.
LSCache holds a separate cache entry per value of each named cookie,
so a public page with a per-bucket variant can stay shareable while
each bucket gets its own entry.

Drupal-native angle: a response carrying the `cookies:NAME` cache
context already declares its variation. The operator just lists which
of those cookies should also become LSCache vary cookies; the
response subscriber emits `X-LiteSpeed-Vary: cookie=NAME` automatically
on matching responses. Stacks on top of public or private cache.

- New `vary_cookies` config: list of cookie name strings.
- Settings form section to manage the list.
- Response subscriber intersects configured cookies with the
  response's `cookies:NAME` cache contexts and emits the vary header
  when there's a match.
- `hook_update_8102()` populates the empty default for sites
  upgrading from 1.0.x or 1.1.x.
- Unit tests cover single-cookie, multi-cookie, no-match, and
  vary-on-top-of-private-cache scenarios.

## Deferred / maybe-never

Ideas worth noting but not actively scoped. Open an issue if any of
these would unblock a real use case.

- URL-based and path-pattern invalidation types in the purger.
- Regex invalidation type.
- Dashboard / status page showing recent purges.
  (The separate `cache_dashboard` project will handle this across
  multiple backends; see the corresponding issue queue.)
- Migration helper for sites moving from LiteSpeed's own
  `litespeedtech/lscache-drupal` GitHub-only module.
