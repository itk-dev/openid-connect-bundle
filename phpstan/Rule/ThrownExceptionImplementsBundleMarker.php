<?php

namespace ItkDev\OpenIdConnectBundle\PHPStan\Rule;

use ItkDev\OpenIdConnect\Exception\OpenIdConnectExceptionInterface;
use ItkDev\OpenIdConnectBundle\Exception\OpenIdConnectBundleExceptionInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Throw_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Every exception thrown from `src/` must implement the bundle or library marker.
 *
 * Two framework-boundary carve-outs (documented in ADR 001):
 *
 *  - HTTP controllers may throw Symfony `HttpExceptionInterface` subclasses; the
 *    kernel reads the status code off them to render the response.
 *  - Authenticators may throw Symfony `AuthenticationException` subclasses; the
 *    security framework catches those to render the failure response.
 *
 * Escape hatch: per-line "phpstan-ignore throw.notMarkerInterface" with a
 * justification comment.
 *
 * @implements Rule<Throw_>
 */
final class ThrownExceptionImplementsBundleMarker implements Rule
{
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {
    }

    public function getNodeType(): string
    {
        return Throw_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $file = $scope->getFile();

        // Only enforce inside src/ — tests, fixtures, and tooling are not part of the contract.
        if (!str_contains($file, '/src/')) {
            return [];
        }

        $thrownExpr = $node->expr;
        if (!$thrownExpr instanceof New_) {
            // `throw $variable` or `throw functionCall()`: type may still be checked elsewhere,
            // but this rule only inspects literal `throw new X(...)` sites.
            return [];
        }

        $classNode = $thrownExpr->class;
        if (!$classNode instanceof Node\Name) {
            return [];
        }

        $className = $scope->resolveName($classNode);
        if (!$this->reflectionProvider->hasClass($className)) {
            return [];
        }

        $reflection = $this->reflectionProvider->getClass($className);
        $inController = str_contains($file, '/src/Controller/');
        $inAuthenticator = str_contains($file, '/src/Security/') && str_ends_with($file, 'Authenticator.php');

        if (
            $reflection->implementsInterface(OpenIdConnectBundleExceptionInterface::class)
            || $reflection->implementsInterface(OpenIdConnectExceptionInterface::class)
        ) {
            return [];
        }

        if ($inController && $reflection->implementsInterface(HttpExceptionInterface::class)) {
            return [];
        }

        if (
            $inAuthenticator
            && (
                AuthenticationException::class === $reflection->getName()
                || $reflection->isSubclassOfClass($this->reflectionProvider->getClass(AuthenticationException::class))
            )
        ) {
            return [];
        }

        $expected = match (true) {
            $inController => sprintf(
                '%s, %s, or %s (controller carve-out)',
                OpenIdConnectBundleExceptionInterface::class,
                OpenIdConnectExceptionInterface::class,
                HttpExceptionInterface::class,
            ),
            $inAuthenticator => sprintf(
                '%s, %s, or %s (authenticator carve-out)',
                OpenIdConnectBundleExceptionInterface::class,
                OpenIdConnectExceptionInterface::class,
                AuthenticationException::class,
            ),
            default => sprintf('%s or %s', OpenIdConnectBundleExceptionInterface::class, OpenIdConnectExceptionInterface::class),
        };

        return [
            RuleErrorBuilder::message(sprintf(
                'Thrown exception %s does not satisfy the bundle exception contract; expected an implementation of %s. See docs/adr/001-marker-interface-exception-hierarchy.md.',
                $className,
                $expected,
            ))
                ->identifier('throw.notMarkerInterface')
                ->build(),
        ];
    }
}
