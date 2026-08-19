<?php

/**
 * @file
 * Minimal kernel for testing
 */

namespace ItkDev\OpenIdConnectBundle\Tests;

use ItkDev\OpenIdConnectBundle\ItkDevOpenIdConnectBundle;
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
     * A cache directory per config set.
     *
     * Without this every kernel in the suite shares `var/cache/test`, so the first
     * container compiled is the one every later test gets — silently, and with
     * whatever configuration that first test happened to use. Any test that boots a
     * different configuration is then asserting against the wrong container.
     */
    #[\Override]
    public function getCacheDir(): string
    {
        return parent::getCacheDir().'/'.substr(hash('xxh128', implode('|', $this->pathToConfigs)), 0, 12);
    }

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
            // Available as a logger a config fixture can point at, so a test can
            // read what the bundle actually wrote through the container.
            $builder->register(TestLogger::class, TestLogger::class)->setPublic(true);
        });

        foreach ($this->pathToConfigs as $path) {
            if (file_exists($path)) {
                $loader->load($path);
            }
        }
    }
}
