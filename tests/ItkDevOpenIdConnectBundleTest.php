<?php

namespace ItkDev\OpenIdConnectBundle\Tests;

use ItkDev\OpenIdConnectBundle\Controller\LoginController;
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
            'open_id_provider_options' => [
                'configuration_url' => 'https://provider.com/openid-configuration',
                'client_id' => 'test_id',
                'client_secret' => 'test_secret',
                'cache_path' => 'test_path',
                'callback_uri' => 'https://app.com/callback_uri'
            ]
        ]);
        $kernel->boot();
        $container = $kernel->getContainer();

        $this->assertTrue($container->has(LoginController::class));

        $controller = $container->get(LoginController::class);
        $this->assertInstanceOf(LoginController::class, $controller);
    }
}
