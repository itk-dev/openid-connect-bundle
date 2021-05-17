<?php
/**
 * @file
 * Minimal kernel for testing
 */

namespace ItkDev\OpenIdConnectBundle\Tests;

use ItkDev\OpenIdConnectBundle\ItkDevOpenIdConnectBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class ItkDevOpenIdConnectBundleTestingKernel.
 */
class ItkDevOpenIdConnectBundleTestingKernel extends Kernel
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;

        parent::__construct('test', true);
    }

    /**
     * {@inheritdoc}
     */
    public function registerBundles()
    {
        return [
            new ItkDevOpenIdConnectBundle(),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function registerContainerConfiguration(LoaderInterface $loader)
    {
        $loader->load(function (ContainerBuilder $containerBuilder) {
            $containerBuilder->loadFromExtension('itkdev_openid_connect', $this->config);
        });
    }
}
