<?php

namespace Drupal\Tests\lscache\Unit;

use Drupal\Core\Render\RendererInterface;
use Drupal\lscache\Controller\LscacheFragmentController;
use Drupal\lscache\Service\LscacheTokenSigner;
use Drupal\Tests\lscache\Unit\Fixture\TrustedCallbackStub;
use Drupal\Tests\lscache\Unit\Fixture\UntrustedCallbackStub;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @coversDefaultClass \Drupal\lscache\Controller\LscacheFragmentController
 * @group lscache
 */
class LscacheFragmentControllerTest extends UnitTestCase {

  /**
   * Builds a controller with mock collaborators.
   *
   * Token signer and renderer are mocked because the trusted-callable
   * check exercised here does not depend on either; the controller's
   * private resolveCallable() reaches the trust check before any
   * rendering logic.
   */
  private function makeController(?ContainerInterface $container = NULL): LscacheFragmentController {
    return new LscacheFragmentController(
      $this->createMock(LscacheTokenSigner::class),
      $this->createMock(RendererInterface::class),
      $container ?? $this->createMock(ContainerInterface::class),
    );
  }

  /**
   * Invokes the private resolveCallable() helper via reflection.
   *
   * Drupal unit tests routinely use reflection to assert behaviour of
   * private helpers when the public surface would not exercise the
   * branch under test.
   */
  private function resolveCallable(LscacheFragmentController $controller, string $callback): ?array {
    $method = new \ReflectionMethod($controller, 'resolveCallable');
    $method->setAccessible(TRUE);
    return $method->invoke($controller, $callback);
  }

  /**
   * @covers ::resolveCallable
   */
  public function testTrustedStaticCallableResolves(): void {
    $controller = $this->makeController();
    $result = $this->resolveCallable(
      $controller,
      TrustedCallbackStub::class . '::staticRender',
    );
    $this->assertIsArray($result);
    $this->assertSame(TrustedCallbackStub::class, $result[0]);
    $this->assertSame('staticRender', $result[1]);
  }

  /**
   * @covers ::resolveCallable
   */
  public function testUntrustedStaticCallableRejected(): void {
    // Defence in depth: even though the class and method exist and the
    // token signature would presumably be valid for a real attacker,
    // resolution must return NULL because the class does not declare
    // itself trusted. This matches Drupal core's #lazy_builder policy.
    $controller = $this->makeController();
    $result = $this->resolveCallable(
      $controller,
      UntrustedCallbackStub::class . '::render',
    );
    $this->assertNull($result);
  }

  /**
   * @covers ::resolveCallable
   */
  public function testTrustedServiceCallableResolves(): void {
    $service = new TrustedCallbackStub();
    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->with('test.trusted_renderer')->willReturn(TRUE);
    $container->method('get')->with('test.trusted_renderer')->willReturn($service);

    $controller = $this->makeController($container);
    $result = $this->resolveCallable($controller, 'test.trusted_renderer:render');

    $this->assertIsArray($result);
    $this->assertSame($service, $result[0]);
    $this->assertSame('render', $result[1]);
  }

  /**
   * @covers ::resolveCallable
   */
  public function testUntrustedServiceCallableRejected(): void {
    $service = new UntrustedCallbackStub();
    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->with('test.untrusted_renderer')->willReturn(TRUE);
    $container->method('get')->with('test.untrusted_renderer')->willReturn($service);

    $controller = $this->makeController($container);
    $result = $this->resolveCallable($controller, 'test.untrusted_renderer:render');

    $this->assertNull($result);
  }

  /**
   * @covers ::resolveCallable
   */
  public function testNonExistentCallbackRejected(): void {
    $controller = $this->makeController();
    $this->assertNull(
      $this->resolveCallable($controller, 'NoSuch\\Class::doesNotMatter'),
    );
  }

  /**
   * @covers ::resolveCallable
   */
  public function testMalformedCallbackRejected(): void {
    // Callback string with neither :: nor : has no recognised shape.
    $controller = $this->makeController();
    $this->assertNull($this->resolveCallable($controller, 'just-some-text'));
  }

}
