<?php

namespace Drupal\lscache_purger\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\lscache_purger\PurgeHost;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin settings form for the LSCache Purger submodule.
 */
class LscachePurgerSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly ClientInterface $httpClient,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('http_client'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['lscache_purger.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lscache_purger_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['purge_host'] = [
      '#type' => 'url',
      '#title' => $this->t('LSCache purge host'),
      '#description' => $this->t("Full http:// or https:// URL of the LiteSpeed origin that receives PURGE requests, without a trailing slash. <strong>Use the local LSWS origin URL, not the public CDN-fronted URL.</strong> On most single-server installs that is <code>http://localhost/</code>. Required for cron-driven purging because the default Purge processor (cron) runs from the CLI, where the module cannot auto-detect the request host."),
      '#placeholder' => 'http://localhost/',
      '#element_validate' => [[static::class, 'validatePurgeHostScheme']],
      '#config_target' => 'lscache_purger.settings:purge_host',
    ];

    $form['purge_host_help'] = [
      '#type' => 'details',
      '#title' => $this->t('Choosing the right purge host'),
      '#open' => FALSE,
      'body' => [
        '#type' => 'markup',
        '#markup' =>
        '<p>' . $this->t('<strong>If LSWS is on the same host as Drupal</strong> (most single-server installs): use <code>http://127.0.0.1</code>. The PURGE request never leaves the box.') . '</p>'
        . '<p>' . $this->t('<strong>If your site sits behind a CDN</strong> (Cloudflare, Fastly, CloudFront, Bunny, Akamai): do <em>not</em> use your public site URL here. CDNs reject the non-standard PURGE method with HTTP 400 before it ever reaches LSWS, and the purger will fail silently with no cache eviction. Use an origin-direct URL instead, such as the internal hostname of your origin server, an internal load balancer, or <code>http://127.0.0.1</code> if Drupal and LSWS share a host.') . '</p>'
        . '<p>' . $this->t('<strong>If LSWS is on a different host</strong> (multi-server setups): use the internal hostname of the LSWS server reachable from PHP, e.g. <code>http://lsws.internal</code>. Make sure that host is reachable from the Drupal application server and that no firewall blocks the PURGE method.') . '</p>'
        . '<p>' . $this->t('Use the <em>Test purge host</em> button below to verify the URL works before relying on it.') . '</p>',
      ],
    ];

    $form['purge_host_header'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Override Host header on PURGE requests'),
      '#description' => $this->t("Leave blank unless <em>LSCache purge host</em> targets localhost on a single-host install. When set, overrides the HTTP <code>Host</code> header on outgoing PURGE requests so LSWS evicts entries cached under your canonical site host rather than the localhost bucket Guzzle would otherwise key under. Enter a bare hostname, e.g. <code>www.example.com</code>, not a full URL."),
      '#placeholder' => 'www.example.com',
      '#element_validate' => [[static::class, 'validatePurgeHostHeader']],
      '#config_target' => 'lscache_purger.settings:purge_host_header',
    ];

    $form['purge_host_header_help'] = [
      '#type' => 'details',
      '#title' => $this->t('Why the Host header matters'),
      '#open' => FALSE,
      'body' => [
        '#type' => 'markup',
        '#markup' =>
        '<p>' . $this->t('LSWS keys its cache entries by the request <code>Host</code> header. Real traffic populates entries under your canonical site host (e.g. <code>www.example.com</code>). When the purger sends a PURGE to <code>http://localhost/</code>, Guzzle derives the Host header from the URL and sends <code>Host: localhost</code>, so LSWS evicts a phantom localhost cache bucket while the real <code>www.example.com</code> entries stay live until their max-age expires.') . '</p>'
        . '<p>' . $this->t('Setting this field to your canonical site host (without scheme or trailing slash) tells the purger to override the Host header explicitly. The PURGE request still goes to localhost, but LSWS evicts under the canonical-host cache key, so editor content saves invalidate as expected.') . '</p>'
        . '<p>' . $this->t('Leave blank if your <em>LSCache purge host</em> is a non-localhost URL or if your LSWS install genuinely keys cache under localhost (some containerised dev setups). The status report row <em>LSCache purger Host header configuration</em> flags the common mismatch with a copy-pasteable suggestion.') . '</p>',
      ],
    ];

    $form['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('HTTP request timeout (seconds)'),
      '#description' => $this->t('How long to wait for the LSCache server to respond to a PURGE request before declaring the invalidation failed. The default of 4 seconds is appropriate for a same-host LSWS deployment; raise it if the purge host is in a different region or behind multiple network hops.'),
      '#min' => 1,
      '#max' => 60,
      '#config_target' => 'lscache_purger.settings:timeout',
    ];

    $form = parent::buildForm($form, $form_state);

    $form['actions']['test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test purge host'),
      '#submit' => ['::testSubmit'],
      '#description' => $this->t('Issues a real PURGE request to the configured host and reports the response. Saves nothing.'),
    ];

    return $form;
  }

  /**
   * Element-level validator: restrict purge_host to http(s) schemes.
   *
   * Drupal's url element type accepts any scheme PHP's filter_var
   * recognises (file, ftp, ldap, gopher, etc.). The HTTP purger only
   * makes sense over http or https; restricting here gives operators
   * an immediate validation error rather than a runtime PURGE failure.
   */
  public static function validatePurgeHostScheme(array &$element, FormStateInterface $form_state): void {
    $value = trim((string) $element['#value']);
    if ($value === '') {
      return;
    }
    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    if ($scheme !== 'http' && $scheme !== 'https') {
      $form_state->setError($element, t('The LSCache purge host must use the http:// or https:// scheme.'));
    }
  }

  /**
   * Element-level validator: purge_host_header must be a bare hostname.
   *
   * Operators commonly paste a full URL here ("https://www.example.com/")
   * instead of the hostname Guzzle expects on the Host header. Rejecting
   * scheme + path at submit time prevents a runtime PURGE failure that
   * would otherwise show only as "no eviction" in editor reports.
   */
  public static function validatePurgeHostHeader(array &$element, FormStateInterface $form_state): void {
    $value = trim((string) $element['#value']);
    if ($value === '') {
      return;
    }
    if (str_contains($value, '://') || str_contains($value, '/')) {
      $form_state->setError($element, t('Enter a bare hostname (e.g. <code>www.example.com</code>) without the http:// scheme or any trailing path. The Host header is a hostname, not a URL.'));
      return;
    }
    if (!preg_match('/^[A-Za-z0-9.\-_:]+$/', $value)) {
      $form_state->setError($element, t('The Host header value contains characters that are not valid in a hostname.'));
    }
  }

  /**
   * Submit handler for the "Test purge host" button.
   *
   * Issues a PURGE request to the currently-entered purge host with a
   * dummy tag value, and surfaces the result as a Drupal status
   * message. Does not save the form. Diagnoses the most common cause
   * of "queue drains but TOTAL_FAILED keeps climbing": a CDN in front
   * of origin rejecting the PURGE method.
   */
  public function testSubmit(array &$form, FormStateInterface $form_state): void {
    $host = trim((string) $form_state->getValue('purge_host'));
    if ($host === '') {
      $this->messenger()->addError($this->t('Enter a purge host before testing.'));
      return;
    }

    $url = rtrim($host, '/') . '/';
    $timeout = max(1, (int) $form_state->getValue('timeout', 4));

    // Mirror the runtime PURGE behaviour: when purge_host_header is set,
    // override the Host header on the test request so the operator sees
    // the same response code production purges will receive. Without
    // this, "Test purge host" can return 200 against localhost while
    // real-traffic PURGEs miss the canonical-host cache bucket.
    $test_headers = ['X-LiteSpeed-Purge' => 'lscache_test_purge_no_real_tag'];
    $host_header_override = trim((string) $form_state->getValue('purge_host_header'));
    if ($host_header_override !== '') {
      $test_headers['Host'] = $host_header_override;
    }

    $request_options = [
      'headers' => $test_headers,
      'timeout' => $timeout,
      'http_errors' => FALSE,
    ];
    // Mirror the runtime purger: skip TLS verification for loopback
    // hosts. A bare 127.0.0.1 or ::1 cannot carry a publicly trusted TLS
    // certificate, so without this the test reports a TLS certificate
    // error for an https loopback host that the real purger would PURGE
    // successfully.
    if (PurgeHost::isLoopback($url)) {
      $request_options['verify'] = FALSE;
    }

    try {
      $response = $this->httpClient->request('PURGE', $url, $request_options);
    }
    catch (GuzzleException $e) {
      $this->messenger()->addError($this->t('PURGE request to @url failed at the network layer: @msg. Check that the host is reachable from the Drupal application server and that the URL is correct.', [
        '@url' => $url,
        '@msg' => $e->getMessage(),
      ]));
      return;
    }

    $status = $response->getStatusCode();
    $cf_ray = $response->getHeaderLine('CF-Ray');
    $server = $response->getHeaderLine('Server');

    if ($status === 200 || $status === 204) {
      $this->messenger()->addStatus($this->t('PURGE request to @url was accepted (HTTP @status, server: @server). This confirms the host is reachable and accepts the PURGE method. It does not by itself confirm that tag-based eviction works on this LiteSpeed build: run <code>drush lscache:diag</code> to verify content actually evicts, and switch <code>purge_strategy</code> to <code>url</code> if it reports tag-PURGE as ineffective.', [
        '@url' => $url,
        '@status' => $status,
        '@server' => $server ?: 'unknown',
      ]));
      return;
    }

    if ($status === 400 && $cf_ray !== '') {
      $this->messenger()->addError($this->t("PURGE rejected by Cloudflare (HTTP 400, CF-Ray: @ray). The URL @url points at a Cloudflare edge, which rejects the PURGE method before it reaches your origin. Use an origin-direct URL such as <code>http://127.0.0.1</code> if Drupal and LSWS share a host, or your origin server's internal hostname.", [
        '@url' => $url,
        '@ray' => $cf_ray,
      ]));
      return;
    }

    if ($status >= 400 && $status < 500) {
      $this->messenger()->addError($this->t('PURGE rejected with HTTP @status (server: @server). If a CDN sits in front of @url it likely rejected the non-standard PURGE method; switch to an origin-direct URL. See the <a href=":help">help section above</a> for guidance.', [
        '@status' => $status,
        '@server' => $server ?: 'unknown',
        '@url' => $url,
        ':help' => Url::fromRoute('<current>')->toString() . '#edit-purge-host-help',
      ]));
      return;
    }

    $this->messenger()->addWarning($this->t('PURGE returned HTTP @status (server: @server). Check the @url destination is configured to accept PURGE requests for cache invalidation.', [
      '@status' => $status,
      '@server' => $server ?: 'unknown',
      '@url' => $url,
    ]));
  }

}
