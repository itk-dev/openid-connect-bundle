<?php

namespace ItkDev\OpenIdConnectBundle\Tests;

use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Controller\LoginController;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use PHPUnit\Framework\TestCase;

/**
 * Class ItkDevOpenIdConnectBundleTest
 */
class ItkDevOpenIdConnectBundleTest extends TestCase
{
    /**
     * Test service wiring.
     */
    public function testServiceWiring()
    {
        $kernel = new ItkDevOpenIdConnectBundleTestingKernel([
            __DIR__ . '/config/services.yml',
            __DIR__ . '/config/framework.yml',
            __DIR__ . '/config/security.yml',
            __DIR__ . '/config/itkdev_openid_connect.yml',
        ]);
        $kernel->boot();
        $container = $kernel->getContainer();

        // LoginController service
        $this->assertTrue($container->has(LoginController::class));

        $controller = $container->get(LoginController::class);
        $this->assertInstanceOf(LoginController::class, $controller);

        // OpenIdLoginAuthenticator service
        $this->assertTrue($container->has(OpenIdLoginAuthenticator::class));
    }
}
