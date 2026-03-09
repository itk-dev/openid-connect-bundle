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
    public function __construct(
        private readonly array $pathToConfigs,
    ) {
        parent::__construct('test', true);
    }

    /**
     * {@inheritdoc}
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
     * {@inheritdoc}
     *
     * @throws \Exception
     */
    public function registerContainerConfiguration(LoaderInterface $loader)
    {
        $loader->load(function (ContainerBuilder $builder) {
            $builder->register(TestAuthenticator::class, TestAuthenticator::class);
        });

        foreach ($this->pathToConfigs as $path) {
            if (file_exists($path)) {
                $loader->load($path);
            }
        }
    }
}
