<?php

namespace ItkDev\OpenIdConnectBundle\Tests;

use ItkDev\OpenIdConnectBundle\Command\UserLoginCommand;
use ItkDev\OpenIdConnectBundle\Controller\LoginController;
use ItkDev\OpenIdConnectBundle\Security\CliLoginTokenAuthenticator;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use PHPUnit\Framework\TestCase;

/**
 * Class ItkDevOpenIdConnectBundleTest.
 */
class ItkDevOpenIdConnectBundleTest extends TestCase
{
    /**
     * Test service wiring.
     */
    public function testServiceWiring()
    {
        $kernel = new ItkDevOpenIdConnectBundleTestingKernel([
            __DIR__.'/config/framework.yml',
            __DIR__.'/config/security.yml',
            __DIR__.'/config/itkdev_openid_connect.yml',
        ]);
        $kernel->boot();
        $container = $kernel->getContainer();

        // OpenIdConfigurationProviderManager
        $this->assertTrue($container->has(OpenIdConfigurationProviderManager::class));
        $manager = $container->get(OpenIdConfigurationProviderManager::class);
        $this->assertInstanceOf(OpenIdConfigurationProviderManager::class, $manager);

        // LoginController service
        $this->assertTrue($container->has(LoginController::class));

        $controller = $container->get(LoginController::class);
        $this->assertInstanceOf(LoginController::class, $controller);

        // Abstract OpenIdLoginAuthenticator service
        $this->assertTrue($container->has(OpenIdLoginAuthenticator::class));

        // CliLoginHelper
        $this->assertTrue($container->has(CliLoginHelper::class));
        $helper = $container->get(CliLoginHelper::class);
        $this->assertInstanceOf(CliLoginHelper::class, $helper);

        // UserLoginCommand
        $this->assertTrue($container->has(UserLoginCommand::class));
        $command = $container->get(UserLoginCommand::class);
        $this->assertInstanceOf(UserLoginCommand::class, $command);

        // CliLoginTokenAuthenticator
        $this->assertTrue($container->has(CliLoginTokenAuthenticator::class));
        $authenticator = $container->get(CliLoginTokenAuthenticator::class);
        $this->assertInstanceOf(CliLoginTokenAuthenticator::class, $authenticator);


    }
}
