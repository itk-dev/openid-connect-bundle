<?php

namespace ItkDev\OpenIdConnectBundle\DependencyInjection;

use ItkDev\OpenIdConnectBundle\Command\UserLoginCommand;
use ItkDev\OpenIdConnectBundle\Controller\LoginController;
use ItkDev\OpenIdConnectBundle\Security\CliLoginTokenAuthenticator;
use ItkDev\OpenIdConnectBundle\Security\OpenIdConfigurationProviderManager;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
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
         *     logging_options: array{logger: string|null, level: string},
         *     openid_providers: array<string, array{options: array<string, mixed>}>
         *  } $config
         */
        $config = $this->processConfiguration($configuration, $configs);

        $this->configureLogging($container, $config['logging_options']);

        $definition = $container->getDefinition(OpenIdConfigurationProviderManager::class);

        $providersConfig = [
            'default_providers_options' => [
                'cacheItemPool' => new Reference($config['cache_options']['cache_pool']),
            ],
            'providers' => array_map(static fn (array $options): array => $options['options'], $config['openid_providers']),
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
     * Apply `logging_options` to the services that log.
     *
     * `OpenIdLoginAuthenticator` is abstract and subclassed by the consuming
     * application, so its subclasses are services the extension cannot name.
     * `registerForAutoconfiguration()` reaches them instead: any autoconfigured
     * subclass gets the same logger and level as the bundle's own services.
     *
     * @param array{logger: string|null, level: string} $options
     */
    private function configureLogging(ContainerBuilder $container, array $options): void
    {
        $logger = null === $options['logger'] ? null : new Reference($options['logger']);

        foreach ([LoginController::class, CliLoginTokenAuthenticator::class] as $id) {
            $definition = $container->getDefinition($id);
            $definition->setArgument('$logLevel', $options['level']);
            if (null !== $logger) {
                $definition->setArgument('$logger', $logger);
            }
        }

        $autoconfigured = $container->registerForAutoconfiguration(OpenIdLoginAuthenticator::class);
        $autoconfigured->addMethodCall('setLogLevel', [$options['level']]);
        if (null !== $logger) {
            // Runs after FrameworkBundle's LoggerAwareInterface autoconfiguration,
            // so the configured logger wins over the application default.
            $autoconfigured->addMethodCall('setLogger', [$logger]);
        }
    }

    #[\Override]
    public function getAlias(): string
    {
        return 'itkdev_openid_connect';
    }
}
