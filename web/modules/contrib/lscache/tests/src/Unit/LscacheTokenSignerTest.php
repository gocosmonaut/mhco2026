<?php

namespace Drupal\Tests\lscache\Unit;

use Drupal\lscache\Service\InvalidTokenException;
use Drupal\lscache\Service\LscacheTokenSigner;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\lscache\Service\LscacheTokenSigner
 * @group lscache
 */
class LscacheTokenSignerTest extends UnitTestCase {

  /**
   * A fixed salt so test tokens are deterministic.
   *
   * Real installs use \Drupal\Core\Site\Settings::getHashSalt() which
   * is per-install secret; here we pin a value so signature comparisons
   * across test methods are stable.
   */
  private const SALT = 'test-salt-deterministic';

  /**
   * The signer under test, instantiated once per test in setUp().
   */
  private LscacheTokenSigner $signer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->signer = new LscacheTokenSigner(self::SALT);
  }

  /**
   * @covers ::sign
   * @covers ::verify
   */
  public function testRoundTripPreservesPayload(): void {
    $token = $this->signer->sign('my_module.cart:render', [42, 'eu', TRUE]);
    $decoded = $this->signer->verify($token);
    $this->assertSame('my_module.cart:render', $decoded['cb']);
    $this->assertSame([42, 'eu', TRUE], $decoded['args']);
  }

  /**
   * @covers ::sign
   */
  public function testTokenIsUrlSafe(): void {
    $token = $this->signer->sign('a.b:c', ['arg with space and / and +']);
    // No padding, no slashes, no plus signs: safe to drop into a URL
    // path segment without further encoding.
    $this->assertStringNotContainsString('=', $token);
    $this->assertStringNotContainsString('/', $token);
    $this->assertStringNotContainsString('+', $token);
    $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[a-f0-9]{64}$/', $token);
  }

  /**
   * @covers ::verify
   */
  public function testTamperedSignatureRejected(): void {
    $token = $this->signer->sign('a.b:c', []);
    [$payload, $sig] = explode('.', $token);
    // Flip the last hex character of the signature.
    $tampered = $payload . '.' . substr($sig, 0, -1) . ($sig[-1] === '0' ? '1' : '0');

    $this->expectException(InvalidTokenException::class);
    $this->signer->verify($tampered);
  }

  /**
   * @covers ::verify
   */
  public function testTamperedPayloadRejected(): void {
    $token = $this->signer->sign('a.b:c', []);
    [$payload, $sig] = explode('.', $token);
    // Re-encode a different payload but keep the original signature.
    $different = rtrim(strtr(base64_encode('{"cb":"x.y:z","args":[]}'), '+/', '-_'), '=');
    $tampered = $different . '.' . $sig;

    $this->expectException(InvalidTokenException::class);
    $this->signer->verify($tampered);
  }

  /**
   * @covers ::verify
   */
  public function testTokenSignedWithDifferentSaltRejected(): void {
    $other = new LscacheTokenSigner('different-salt');
    $token = $other->sign('a.b:c', []);

    $this->expectException(InvalidTokenException::class);
    $this->signer->verify($token);
  }

  /**
   * @covers ::verify
   */
  public function testMalformedTokenRejected(): void {
    $this->expectException(InvalidTokenException::class);
    $this->signer->verify('not-a-token');
  }

  /**
   * @covers ::verify
   */
  public function testEmptyTokenRejected(): void {
    $this->expectException(InvalidTokenException::class);
    $this->signer->verify('');
  }

}
