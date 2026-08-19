<?php

namespace ItkDev\OpenIdConnectBundle\Tests;

use ItkDev\OpenIdConnectBundle\Command\UserLoginCommand;
use ItkDev\OpenIdConnectBundle\Controller\LoginController;
use ItkDev\OpenIdConnectBundle\DependencyInjection\ItkDevOpenIdConnectExtension;
use ItkDev\OpenIdConnectBundle\EventSubscriber\AuthenticationAuditSubscriber;
use ItkDev\OpenIdConnectBundle\ItkDevOpenIdConnectBundle;
use ItkDev\OpenIdConnectBundle\Log\AuthenticationAuditLogger;
use ItkDev\OpenIdConnectBundle\Security\CliLoginTokenAuthenticator;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use ItkDev\OpenIdConnectBundle\Util\ClientSecretExpiryChecker;
use ItkDev\OpenIdConnectBundle\Util\ClientSecretExpiryStatus;
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
    public function testServiceWiring(): void
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

        // AuthenticationAuditLogger is always wired, but off unless configured,
        // and the subscriber is absent entirely while it is off.
        $this->assertTrue($container->has(AuthenticationAuditLogger::class));
        $auditLogger = $container->get(AuthenticationAuditLogger::class);
        $this->assertInstanceOf(AuthenticationAuditLogger::class, $auditLogger);
        $this->assertFalse($auditLogger->isEnabled());
        $this->assertFalse($container->has(AuthenticationAuditSubscriber::class));

        // ClientSecretExpiryChecker
        $this->assertTrue($container->has(ClientSecretExpiryChecker::class));
        $checker = $container->get(ClientSecretExpiryChecker::class);
        $this->assertInstanceOf(ClientSecretExpiryChecker::class, $checker);
    }

    /**
     * The expiry date must be configurable from an environment variable.
     *
     * This boots a real container because that is the only place Symfony's
     * placeholder handling runs: a bare `Processor` sees the literal
     * `%env(string:...)%` string, so a node that refuses environment variables
     * looks fine in a unit test and fails in every deployment. 5.1.0 shipped
     * exactly that bug.
     */
    public function testClientSecretExpiresAtCanComeFromAnEnvironmentVariable(): void
    {
        $_ENV['TEST_OIDC_SECRET_EXPIRES_AT'] = '2027-01-31';

        try {
            $kernel = new ItkDevOpenIdConnectBundleTestingKernel([
                __DIR__.'/config/framework.yml',
                __DIR__.'/config/security.yml',
                __DIR__.'/config/itkdev_openid_connect_env_expiry.yml',
            ]);
            $kernel->boot();

            $checker = $kernel->getContainer()->get(ClientSecretExpiryChecker::class);
            $this->assertInstanceOf(ClientSecretExpiryChecker::class, $checker);

            // Resolved at runtime, from the environment.
            $status = $checker->getStatus('test_provider_1');
            $this->assertSame(ClientSecretExpiryStatus::Ok, $status->status);
            $this->assertNotNull($status->expiresAt);
            $this->assertSame('2027-01-31', $status->expiresAt->format('Y-m-d'));
        } finally {
            unset($_ENV['TEST_OIDC_SECRET_EXPIRES_AT']);
        }
    }

    /**
     * Every environment-configurable option, configured from the environment.
     *
     * This is the guard the suite was missing. A bare `Processor` never substitutes
     * Symfony's placeholder fixtures, so a node that rejects `%env()%` values passes
     * every unit test and fails in every deployment — which is what 5.1.0 shipped
     * with `client_secret_expires_at`. Compiling a container is the only way to see
     * it, so any option a deployment would feed from the environment belongs here.
     */
    public function testEveryEnvironmentConfigurableOptionAcceptsAnEnvironmentVariable(): void
    {
        $env = [
            'TEST_CLI_LOGIN_ROUTE' => 'route_test',
            'TEST_AUDIT_ENABLED' => 'true',
            'TEST_AUDIT_IDENTIFIER' => 'hashed',
            'TEST_WARNING_DAYS' => '14',
            'TEST_METADATA_URL' => 'https://provider.example.org/openid-configuration',
            'TEST_CLIENT_ID' => 'test_id',
            'TEST_CLIENT_SECRET' => 'test_secret',
            'TEST_SECRET_EXPIRES_AT' => '2027-01-31',
            'TEST_REDIRECT_URI' => 'https://app.example.org/callback_uri',
            'TEST_ALLOW_HTTP' => 'false',
            'TEST_LEEWAY' => '5',
            'TEST_CACHE_DURATION' => '3600',
            'TEST_TIMEOUT' => '2.5',
        ];
        foreach ($env as $name => $value) {
            $_ENV[$name] = $value;
        }

        try {
            $kernel = new ItkDevOpenIdConnectBundleTestingKernel([
                __DIR__.'/config/framework.yml',
                __DIR__.'/config/security.yml',
                __DIR__.'/config/itkdev_openid_connect_all_env.yml',
            ]);
            $kernel->boot();
            $container = $kernel->getContainer();

            // Resolved from the environment, not from a literal.
            $checker = $container->get(ClientSecretExpiryChecker::class);
            $this->assertInstanceOf(ClientSecretExpiryChecker::class, $checker);
            $status = $checker->getStatus('test_provider_1');
            $this->assertSame(ClientSecretExpiryStatus::Ok, $status->status);
            $this->assertNotNull($status->expiresAt);
            $this->assertSame('2027-01-31', $status->expiresAt->format('Y-m-d'));

            // The audit trail switched on from the environment too.
            $auditLogger = $container->get(AuthenticationAuditLogger::class);
            $this->assertInstanceOf(AuthenticationAuditLogger::class, $auditLogger);
            $this->assertTrue($auditLogger->isEnabled());
            $this->assertTrue($container->has(AuthenticationAuditSubscriber::class));

            // And the provider still builds from environment-fed options.
            $manager = $container->get(OpenIdConfigurationProviderManager::class);
            $this->assertInstanceOf(OpenIdConfigurationProviderManager::class, $manager);
            $this->assertSame(['test_provider_1'], $manager->getProviderKeys());
        } finally {
            foreach (array_keys($env) as $name) {
                unset($_ENV[$name]);
            }
        }
    }

    /**
     * Test that the custom container extension is created and memoized.
     */
    public function testGetContainerExtension(): void
    {
        $bundle = new ItkDevOpenIdConnectBundle();

        $extension = $bundle->getContainerExtension();
        $this->assertInstanceOf(ItkDevOpenIdConnectExtension::class, $extension);

        // Repeated calls must return the same instance, not recreate it.
        $this->assertSame($extension, $bundle->getContainerExtension());
    }
}
