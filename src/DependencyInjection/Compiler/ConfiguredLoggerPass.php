<?php

namespace ItkDev\OpenIdConnectBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Make `logging_options.logger` the logger an authenticator actually receives.
 *
 * `OpenIdLoginAuthenticator` is `LoggerAwareInterface`, so FrameworkBundle
 * autoconfigures a `setLogger()` call with the application logger onto every
 * subclass. The bundle registers a call of its own for the configured logger, and
 * both survive: PHP runs whichever is last.
 *
 * Which one that is comes down to the order the extensions were loaded, which is
 * the order the bundles were registered. The conventional order puts FrameworkBundle
 * first, so the bundle's call lands last and the configured logger wins; register
 * this bundle first and the application logger silently wins instead —
 * `itkdev_openid_connect.null_logger`, the documented way to turn this bundle's
 * logging off, included.
 *
 * Method calls carry no priority, so making that deterministic means rewriting them
 * once the instanceof conditionals that produced them have been resolved.
 */
final class ConfiguredLoggerPass implements CompilerPassInterface
{
    /**
     * Where the extension leaves the configured service id.
     *
     * A parameter rather than a constructor argument because Symfony refuses a pass
     * added from an extension, so this one is registered in `build()` — before any
     * configuration has been read. The pass consumes the parameter, so it does not
     * linger in the consumer's container.
     */
    public const string LOGGER_PARAMETER = 'itkdev_openid_connect.configured_logger';

    private const string METHOD = 'setLogger';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::LOGGER_PARAMETER)) {
            return;
        }

        $loggerId = $container->getParameter(self::LOGGER_PARAMETER);
        $container->getParameterBag()->remove(self::LOGGER_PARAMETER);

        if (!is_string($loggerId)) {
            return;
        }

        foreach ($container->getDefinitions() as $definition) {
            $this->giveTheConfiguredLoggerTheLastWord($definition, $loggerId);
        }
    }

    /**
     * Definitions are recognised by the call the bundle put there, not by their
     * class. Checking classes would mean autoloading every class in the container,
     * which is fatal for a consumer whose container names a class from a package it
     * has not installed.
     */
    private function giveTheConfiguredLoggerTheLastWord(Definition $definition, string $loggerId): void
    {
        /** @var list<array{0: string, 1: mixed[]}> $calls */
        $calls = $definition->getMethodCalls();

        $kept = [];
        $wanted = false;

        foreach ($calls as $call) {
            if (self::METHOD === $call[0]) {
                // Every existing call goes, the configured one being appended below.
                // Keeping FrameworkBundle's would put it after ours again.
                $wanted = $wanted || $this->references($call[1], $loggerId);

                continue;
            }

            $kept[] = $call;
        }

        // Without the bundle's own call this is some other LoggerAware service, or an
        // authenticator whose consumer turned autoconfiguration off — which the README
        // documents as opting out of the bundle's logging. Neither is ours to change,
        // and returning here leaves the definition exactly as it was.
        if (!$wanted) {
            return;
        }

        $kept[] = [self::METHOD, [new Reference($loggerId)]];

        $definition->setMethodCalls($kept);
    }

    /**
     * @param mixed[] $arguments
     */
    private function references(array $arguments, string $loggerId): bool
    {
        return ($arguments[0] ?? null) instanceof Reference && $loggerId === (string) $arguments[0];
    }
}
