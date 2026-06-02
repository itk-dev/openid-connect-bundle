<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Exception;

use ItkDev\OpenIdConnect\Exception\HttpException as LibraryHttpException;
use ItkDev\OpenIdConnect\Exception\OpenIdConnectExceptionInterface;
use ItkDev\OpenIdConnectBundle\Exception\CacheException;
use ItkDev\OpenIdConnectBundle\Exception\InvalidProviderException;
use ItkDev\OpenIdConnectBundle\Exception\ItkOpenIdConnectBundleException;
use ItkDev\OpenIdConnectBundle\Exception\OpenIdConnectBundleExceptionInterface;
use ItkDev\OpenIdConnectBundle\Exception\TokenNotFoundException;
use ItkDev\OpenIdConnectBundle\Exception\UserDoesNotExistException;
use ItkDev\OpenIdConnectBundle\Exception\UsernameDoesNotExistException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks the bundle's exception contract (see
 * docs/adr/001-marker-interface-exception-hierarchy.md):
 *
 * - Every concrete bundle exception implements {@see OpenIdConnectBundleExceptionInterface}
 *   (and therefore {@see OpenIdConnectExceptionInterface} from the upstream library).
 * - Each concrete extends the SPL type that best describes its failure category.
 * - A single `catch (OpenIdConnectExceptionInterface $e)` matches every concrete
 *   from both the bundle and the upstream library.
 *
 * A change that breaks any of these properties is a MAJOR version bump per
 * SemVer commitments — failing this test class is the early warning.
 */
class ExceptionHierarchyTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<\Throwable>, class-string<\Throwable>}>
     */
    public static function concreteProvider(): iterable
    {
        // Invalid input to a public method → \InvalidArgumentException
        yield 'InvalidProviderException' => [InvalidProviderException::class, \InvalidArgumentException::class];
        yield 'UsernameDoesNotExistException' => [UsernameDoesNotExistException::class, \InvalidArgumentException::class];

        // Runtime conditions → \RuntimeException
        yield 'CacheException' => [CacheException::class, \RuntimeException::class];
        yield 'TokenNotFoundException' => [TokenNotFoundException::class, \RuntimeException::class];
        yield 'UserDoesNotExistException' => [UserDoesNotExistException::class, \RuntimeException::class];
    }

    /**
     * @param class-string<\Throwable> $concrete
     * @param class-string<\Throwable> $expectedSplParent
     */
    #[DataProvider('concreteProvider')]
    public function testConcreteImplementsBundleMarker(string $concrete, string $expectedSplParent): void
    {
        $instance = new $concrete('test');
        $this->assertInstanceOf(OpenIdConnectBundleExceptionInterface::class, $instance);
    }

    /**
     * @param class-string<\Throwable> $concrete
     * @param class-string<\Throwable> $expectedSplParent
     */
    #[DataProvider('concreteProvider')]
    public function testConcreteImplementsLibraryMarker(string $concrete, string $expectedSplParent): void
    {
        $instance = new $concrete('test');
        $this->assertInstanceOf(OpenIdConnectExceptionInterface::class, $instance);
    }

    /**
     * @param class-string<\Throwable> $concrete
     * @param class-string<\Throwable> $expectedSplParent
     */
    #[DataProvider('concreteProvider')]
    public function testConcreteExtendsExpectedSplParent(string $concrete, string $expectedSplParent): void
    {
        $instance = new $concrete('test');
        $this->assertInstanceOf($expectedSplParent, $instance);
    }

    /**
     * @param class-string<\Throwable> $concrete
     * @param class-string<\Throwable> $expectedSplParent
     */
    #[DataProvider('concreteProvider')]
    public function testCatchByBundleMarkerMatchesEveryConcrete(string $concrete, string $expectedSplParent): void
    {
        try {
            throw new $concrete('test');
        } catch (OpenIdConnectBundleExceptionInterface $caught) {
            $this->assertInstanceOf($concrete, $caught);

            return;
        }
        // @phpstan-ignore deadCode.unreachable (safety net if a future regression breaks the catch-by-marker contract)
        $this->fail('Catch on OpenIdConnectBundleExceptionInterface should have matched '.$concrete);
    }

    /**
     * Cross-package contract: a single `catch (OpenIdConnectExceptionInterface $e)`
     * must catch failures from both the library and the bundle. This is the whole
     * point of the bundle marker extending the library marker.
     */
    public function testLibraryMarkerCatchesBothPackages(): void
    {
        $caught = [];

        foreach ([new LibraryHttpException('lib'), new CacheException('bundle')] as $exception) {
            try {
                throw $exception;
            } catch (OpenIdConnectExceptionInterface $e) {
                $caught[] = $e::class;
            }
        }

        $this->assertSame([LibraryHttpException::class, CacheException::class], $caught);
    }

    public function testDeprecatedAbstractBaseImplementsBundleMarker(): void
    {
        // `ItkOpenIdConnectBundleException` is kept as a deprecated alias through 5.x.
        // Concrete bundle exceptions no longer extend it, but it still implements the
        // marker so any consumer-defined subclass remains catchable via the marker.
        // PHPStan can statically prove the assertion holds today; the test exists so
        // the day a refactor removes the implements, the failure is loud.
        $deprecated = ItkOpenIdConnectBundleException::class; // @phpstan-ignore classConstant.deprecatedClass (the test asserts a property of this deprecated class on purpose)
        // @phpstan-ignore method.alreadyNarrowedType (the assertion is the guard — PHPStan proves it today; the test fails the day the guard stops holding)
        $this->assertTrue(
            // @phpstan-ignore function.alreadyNarrowedType (same as above — the static proof IS the contract being asserted)
            is_subclass_of($deprecated, OpenIdConnectBundleExceptionInterface::class),
            'Deprecated abstract base must continue to implement the bundle marker through 5.x.',
        );
    }
}
