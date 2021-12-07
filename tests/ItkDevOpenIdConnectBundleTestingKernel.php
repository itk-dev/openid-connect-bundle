<?php

/**
 * @file
 * Minimal kernel for testing
 */

namespace ItkDev\OpenIdConnectBundle\Tests;

use Exception;
use ItkDev\OpenIdConnectBundle\ItkDevOpenIdConnectBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpKernel\Kernel;

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
     * @throws Exception
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
