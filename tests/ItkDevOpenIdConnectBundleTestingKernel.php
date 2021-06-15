<?php

/**
 * @file
 * Minimal kernel for testing
 */

namespace ItkDev\OpenIdConnectBundle\Tests;

use ItkDev\OpenIdConnectBundle\ItkDevOpenIdConnectBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
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
    private $pathToConfigs;

    public function __construct(array $pathToConfigs)
    {
        $this->pathToConfigs = $pathToConfigs;
        parent::__construct('test', true);
    }

    /**
     * {@inheritdoc}
     */
    public function registerBundles()
    {
        return [
            new ItkDevOpenIdConnectBundle(),
            new FrameworkBundle(),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function registerContainerConfiguration(LoaderInterface $loader)
    {
        foreach ($this->pathToConfigs as $path) {
            if (file_exists($path)) {
                $loader->load($path);
            }
        }
    }
}
