<?php

namespace Drupal\lscache\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;

/**
 * Admin settings form for LSCache.
 */
class LscacheSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['lscache.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lscache_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable LSCache header injection'),
      '#description' => $this->t('When enabled, cacheable responses receive <code>X-LiteSpeed-Tag</code> and <code>X-LiteSpeed-Cache-Control</code> headers so LiteSpeed can cache and later invalidate pages by Drupal cache tag. Disable to verify the site behaves correctly without LSCache involvement.'),
      '#config_target' => 'lscache.settings:enabled',
    ];

    $form['default_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Default TTL'),
      '#description' => $this->t("LSCache <strong>server-side</strong> cache lifetime in seconds, sent as <code>X-LiteSpeed-Cache-Control: public,max-age=N</code>. This is the copy LiteSpeed serves from RAM; the purger evicts it the instant content changes, so it can safely be long (a day or more). It is separate from Drupal's <strong>browser</strong> page-cache TTL (<code>system.performance:cache.page.max_age</code>, under Configuration &rsaquo; Development &rsaquo; Performance), which sets the <code>Cache-Control: max-age</code> browsers hold and which nothing can purge. Recommended shape: a short browser TTL (a few minutes) alongside a longer, purge-managed server TTL here. Set to 0 to omit the header and defer to Drupal's own caching directives."),
      '#min' => 0,
      '#config_target' => 'lscache.settings:default_ttl',
    ];

    $form['tag_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cache tag prefix'),
      '#description' => $this->t('Prefix prepended to every cache tag in the <code>X-LiteSpeed-Tag</code> header, so multiple Drupal sites sharing one LSCache can purge independently. Leave empty to auto-derive a short per-install hash (recommended). Set a value like <code>site-a:</code> only if you need to control the prefix explicitly.'),
      '#config_target' => 'lscache.settings:tag_prefix',
    ];

    $form['tag_filter'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Filter low-value cache tags'),
      '#description' => $this->t('When enabled (default), tags that add no invalidation value are dropped from the <code>X-LiteSpeed-Tag</code> header. This mirrors the behaviour of the LiteSpeed-published <code>lite_speed_cache</code> module and is required for LSWS to consistently cache responses on many environments. Filtered tags include <code>config:*</code>, <code>user:*</code>, <code>taxonomy_term*</code>, <code>*_view</code>, <code>*_list</code>, <code>http_response</code>, and <code>rendered</code>. Disable only if you have a specific reason to expose the full raw tag list.'),
      '#config_target' => 'lscache.settings:tag_filter',
    ];

    $form['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debug logging'),
      '#description' => $this->t('Log the tag header payload on every cacheable response to the <em>lscache</em> channel. Useful during setup to confirm tags are being emitted, but produces significant log volume; leave off in production.'),
      '#config_target' => 'lscache.settings:debug',
    ];

    $form['private_cache'] = [
      '#type' => 'details',
      '#title' => $this->t('Private cache (authenticated users)'),
      '#open' => FALSE,
      '#description' => $this->t("Authenticated user pages can be held in LSCache's per-user private cache. Drupal already declares which responses vary per user via cache contexts (<code>user</code>, <code>user.permissions</code>, <code>session</code>, etc.); this module reads those contexts and emits <code>X-LiteSpeed-Cache-Control: private,max-age=N</code> automatically. <strong>Off by default:</strong> private cache holds a separate copy per user, so storage scales with active users."),
    ];

    $form['private_cache']['private_cache_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable private-cache mode'),
      '#description' => $this->t('When enabled, responses whose Drupal cache contexts match the trigger list below get <code>X-LiteSpeed-Cache-Control: private,max-age=N</code> instead of being passed through to PHP. Requires <code>CacheLookup public on private on</code> in <code>.htaccess</code>; the status report will warn if this directive is missing.'),
      '#config_target' => 'lscache.settings:private_cache.enabled',
    ];

    $form['private_cache']['private_cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Private-cache TTL (seconds)'),
      '#description' => $this->t('Lifetime of private-cached responses. Default 600 (10 minutes), much shorter than the public default to bound per-user cache footprint. Set to 0 to omit the Cache-Control directive on private responses and defer to whatever Drupal emits.'),
      '#min' => 0,
      '#config_target' => 'lscache.settings:private_cache.ttl',
    ];

    $form['private_cache']['private_cache_contexts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cache contexts that trigger private mode'),
      '#description' => $this->t('One Drupal cache context per line. A response carrying any of these contexts in its cacheable metadata is emitted as private-cache. Supports namespace-prefix matching: <code>user</code> matches <code>user</code>, <code>user.permissions</code>, <code>user.roles</code>, etc. To match a specific cookie, use the exact context name (e.g. <code>cookies:foo</code>). The defaults cover the vast majority of authenticated Drupal pages.'),
      '#rows' => 4,
      '#config_target' => new ConfigTarget(
        'lscache.settings',
        'private_cache.contexts',
        // Stored as a sequence in config; rendered as one-per-line text.
        // The `?array` parameter type guards against the form WSOD
        // observed in 1.3.0-beta1 field testing: when active config
        // drifted to a state where `private_cache.contexts` was missing,
        // the strict `array` parameter triggered a TypeError that took
        // out the entire settings page. Defaulting NULL to an empty
        // list lets the form render even on partial config; the
        // backfill update hook handles repopulating the defaults.
        fromConfig: static fn(?array $contexts): string => implode("\n", $contexts ?? []),
        toConfig: static fn(string $value): array => array_values(array_filter(array_map('trim', explode("\n", $value)))),
      ),
    ];

    $form['vary_cookies'] = [
      '#type' => 'details',
      '#title' => $this->t('Vary cookies'),
      '#open' => FALSE,
      '#description' => $this->t('LSCache holds a separate cache entry per value of each named cookie. Useful for variants of a public page that depend on cookie state but not on user identity, for example mobile vs. desktop, currency, country, or A/B test buckets. Each cookie listed below has <code>X-LiteSpeed-Vary: cookie=NAME</code> emitted automatically on every cacheable response.'),
    ];

    $form['vary_cookies']['vary_cookies_list'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cookie names that vary the cache'),
      '#description' => $this->t('One cookie name per line. Each named cookie is added to the <code>X-LiteSpeed-Vary</code> header on every cacheable response. LSWS keys its cache by the value of each named cookie at request time; cookies absent from a given request collapse to "no variance" cleanly. Leave empty to disable vary-cookie emission entirely. <strong>Note:</strong> emitting the header from this module is necessary but not sufficient. LSWS also needs a matching <code>CacheVary cookie=NAME</code> directive at the server or vhost level. See the README "Required matching LSWS server-side directive" section.'),
      '#rows' => 4,
      '#config_target' => new ConfigTarget(
        'lscache.settings',
        'vary_cookies',
        // `?array` parameter type guards against config drift for the
        // same reason as `private_cache.contexts` above. NULL falls back
        // to an empty list so the form renders.
        fromConfig: static fn(?array $cookies): string => implode("\n", $cookies ?? []),
        toConfig: static fn(string $value): array => array_values(array_filter(array_map('trim', explode("\n", $value)))),
      ),
    ];

    return parent::buildForm($form, $form_state);
  }

}
