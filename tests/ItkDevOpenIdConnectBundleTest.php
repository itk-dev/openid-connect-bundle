<?php

use ItkDev\OpenIdConnectBundle\Controller\LoginController;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use ItkDev\OpenIdConnectBundle\Tests\ItkDevOpenIdConnectBundleTestingKernel;
use PHPUnit\Framework\TestCase;

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

        $controller = $container->get('itkdev.openid_login_controller');
        $this->assertInstanceOf(LoginController::class, $controller);
    }
}