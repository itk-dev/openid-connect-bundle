<?php

namespace ItkDev\OpenIdConnectBundle\DependencyInjection;

use ItkDev\OpenIdConnectBundle\Command\UserLoginCommand;
use ItkDev\OpenIdConnectBundle\Controller\LoginController;
use ItkDev\OpenIdConnectBundle\DependencyInjection\Compiler\ConfiguredLoggerPass;
use ItkDev\OpenIdConnectBundle\EventSubscriber\AuthenticationAuditSubscriber;
use ItkDev\OpenIdConnectBundle\Log\AuthenticationAuditLogger;
use ItkDev\OpenIdConnectBundle\Security\CliLoginTokenAuthenticator;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use ItkDev\OpenIdConnectBundle\Util\ClientSecretExpiryChecker;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

class ItkDevOpenIdConnectExtension extends Extension
{
    /**
     * @throws \Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');

        $configuration = new Configuration();
        /** @var array{
         *     cache_options: array{cache_pool: string},
         *     cli_login_options: array{route: string},
         *     user_provider: string|null,
         *     logging_options: array{logger: string|null},
         *     audit_options: array{enabled: bool, logger: string|null, identifier: string},
         *     secret_expiry_options: array{warning_days: int},
         *     openid_providers: array<string, array{options: array<string, mixed>}>
         *  } $config
         */
        $config = $this->processConfiguration($configuration, $configs);

        $this->configureLogging($container, $config['logging_options']);
        $this->configureAuditLogging($container, $config['audit_options']);
        $this->configureSecretExpiry($container, $config['openid_providers'], $config['secret_expiry_options']);

        $definition = $container->getDefinition(OpenIdConfigurationProviderManager::class);

        $providersConfig = [
            'default_providers_options' => [
                'cacheItemPool' => new Reference($config['cache_options']['cache_pool']),
            ],
            'providers' => array_map(
                // client_secret_expires_at is the bundle's own bookkeeping, not a
                // provider option, so it is stripped before reaching the manager —
                // whose strict array shape would otherwise have to grow a key the
                // upstream library knows nothing about.
                static function (array $options): array {
                    $providerOptions = $options['options'];
                    unset($providerOptions['client_secret_expires_at']);

                    return $providerOptions;
                },
                $config['openid_providers'],
            ),
        ];
        $definition->replaceArgument('$config', $providersConfig);

        $definition = $container->getDefinition(CliLoginHelper::class);
        $definition->replaceArgument('$cache', new Reference($config['cache_options']['cache_pool']));

        $definition = $container->getDefinition(UserLoginCommand::class);
        $definition->replaceArgument('$cliLoginRoute', $config['cli_login_options']['route']);
        if (null !== $config['user_provider']) {
            $definition->setArgument('$userProvider', new Reference($config['user_provider']));
        }

        $definition = $container->getDefinition(CliLoginTokenAuthenticator::class);
        $definition->replaceArgument('$cliLoginRoute', $config['cli_login_options']['route']);
    }

    /**
     * Point the services that log at the configured logger.
     *
     * Left unset, they keep the logger Symfony autowires, which is what puts the
     * records on the `openid_connect` channel; the application's own handler
     * configuration then decides which of them are kept. Severity is fixed per
     * failure mode in the emitters and is not configurable.
     *
     * @param array{logger: string|null} $options
     */
    private function configureLogging(ContainerBuilder $container, array $options): void
    {
        if (null === $options['logger']) {
            return;
        }

        $logger = new Reference($options['logger']);

        foreach ([LoginController::class, CliLoginTokenAuthenticator::class] as $id) {
            $container->getDefinition($id)->setArgument('$logger', $logger);
        }

        // `OpenIdLoginAuthenticator` is abstract and subclassed by the consuming
        // application, so its subclasses are services this extension cannot name.
        // Autoconfiguration reaches them.
        $container->registerForAutoconfiguration(OpenIdLoginAuthenticator::class)
            ->addMethodCall('setLogger', [$logger]);

        // Whether this call or FrameworkBundle's is the one that takes effect depends
        // on bundle registration order: see ConfiguredLoggerPass, which reads this
        // parameter and settles it.
        $container->setParameter(ConfiguredLoggerPass::LOGGER_PARAMETER, $options['logger']);
    }

    /**
     * Apply `audit_options`.
     *
     * @param array{enabled: bool, logger: string|null, identifier: string} $options
     */
    private function configureAuditLogging(ContainerBuilder $container, array $options): void
    {
        $definition = $container->getDefinition(AuthenticationAuditLogger::class);
        $definition->replaceArgument('$enabled', $options['enabled']);
        $definition->replaceArgument('$identifierMode', $options['identifier']);

        if (null !== $options['logger']) {
            $definition->setArgument('$logger', new Reference($options['logger']));
        }

        // Only referenced when hashing is asked for, so installations on the
        // default keep working without a configured framework secret.
        if (AuthenticationAuditLogger::IDENTIFIER_HASHED === $options['identifier']) {
            $definition->setArgument('$identifierSecret', '%kernel.secret%');
        }

        if (false === $options['enabled']) {
            // An optimisation for the literal case only: with `enabled` coming from
            // an environment variable this is an unresolved placeholder, so the
            // subscriber stays registered and returns early instead. Its own guard
            // is what guarantees nothing is assembled either way.
            $container->removeDefinition(AuthenticationAuditSubscriber::class);
        }
    }

    /**
     * Wire the expiry checker.
     *
     * @param array<string, array{options: array<string, mixed>}> $providers
     * @param array{warning_days: int}                            $options
     */
    private function configureSecretExpiry(ContainerBuilder $container, array $providers, array $options): void
    {
        $expiryDates = [];

        foreach ($providers as $providerKey => $provider) {
            $expiresAt = $provider['options']['client_secret_expires_at'] ?? null;
            // Required by the configuration, so a null here means an environment
            // variable that resolved to something that is not a string.
            // ClientSecretExpiryChecker reports that at runtime.
            $expiryDates[$providerKey] = is_string($expiresAt) ? $expiresAt : null;
        }

        $definition = $container->getDefinition(ClientSecretExpiryChecker::class);
        $definition->replaceArgument('$expiryDates', $expiryDates);
        $definition->replaceArgument('$warningDays', $options['warning_days']);
    }

    #[\Override]
    public function getAlias(): string
    {
        return 'itkdev_openid_connect';
    }
}
