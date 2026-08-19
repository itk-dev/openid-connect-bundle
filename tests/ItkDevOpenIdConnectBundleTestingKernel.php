<?php

/**
 * @file
 * Minimal kernel for testing
 */

namespace ItkDev\OpenIdConnectBundle\Tests;

use ItkDev\OpenIdConnectBundle\ItkDevOpenIdConnectBundle;
use ItkDev\OpenIdConnectBundle\Tests\Security\ConsumerAuthenticator;
use ItkDev\OpenIdConnectBundle\Tests\Security\ProtectedController;
use ItkDev\OpenIdConnectBundle\Tests\Security\TestAuthenticator;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Class ItkDevOpenIdConnectBundleTestingKernel.
 */
class ItkDevOpenIdConnectBundleTestingKernel extends Kernel
{
    /**
     * @param list<string> $pathToConfigs
     */
    public function __construct(
        private readonly array $pathToConfigs,
    ) {
        parent::__construct('test', true);
    }

    /**
     * A cache directory per config set, and under Infection per process.
     *
     * Per config set, because otherwise every kernel in the suite shares
     * `var/cache/test`: the first container compiled is the one every later test
     * gets, silently, with whatever configuration that first test happened to use.
     *
     * Per process under Infection, because it substitutes a mutated file through an
     * include interceptor rather than by writing to disk. Nothing Symfony tracks as a
     * resource changes, so a mutant is served the cached container and every mutation
     * of compile-time code survives by default. Each mutant runs in its own process,
     * so the pid separates them. Plain runs stay on the shared directory: they have
     * nothing to isolate, and a recompile per process is a cost with no return.
     */
    #[\Override]
    public function getCacheDir(): string
    {
        $key = substr(hash('xxh128', implode('|', $this->pathToConfigs)), 0, 12);

        if (false !== getenv('INFECTION')) {
            $key .= '-'.getmypid();
        }

        return parent::getCacheDir().'/'.$key;
    }

    /**
     * This bundle is registered before FrameworkBundle deliberately. It is the
     * unconventional order, and the one where autoconfigured method calls land in the
     * losing order — so it is the order that holds ConfiguredLoggerPass to its job.
     */
    public function registerBundles(): iterable
    {
        return [
            new ItkDevOpenIdConnectBundle(),
            new SecurityBundle(),
            new FrameworkBundle(),
        ];
    }

    /**
     * @throws \Exception
     */
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $builder) {
            $builder->register(TestAuthenticator::class, TestAuthenticator::class);
            // Autowired and autoconfigured, the way a consumer registers its own
            // authenticator: autoconfiguration is what delivers the configured logger
            // to `setLogger()`, and without it this fixture gets a NullLogger.
            $builder->register(ConsumerAuthenticator::class, ConsumerAuthenticator::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true);
            // A consumer who turned autoconfiguration off. Nothing calls setLogger on
            // this one, and nothing should start.
            $builder->register(ConsumerAuthenticator::class.'.not_autoconfigured', ConsumerAuthenticator::class)
                ->setAutowired(true)
                ->setPublic(true);
            $builder->register(ProtectedController::class, ProtectedController::class)->setPublic(true);
            // Available as a logger a config fixture can point at, so a test can
            // read what the bundle actually wrote through the container.
            $builder->register(TestLogger::class, TestLogger::class)->setPublic(true);
            // Aliases are resolved out of method calls before this bundle's compiler
            // pass runs, so a logger configured by alias needs its own coverage.
            $builder->setAlias('test.logger_alias', TestLogger::class);
        });

        foreach ($this->pathToConfigs as $path) {
            if (file_exists($path)) {
                $loader->load($path);
            }
        }
    }
}
