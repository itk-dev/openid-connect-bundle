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
         *     logging_options: array{logger: string|null},
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
        // Autoconfiguration reaches them, and runs after FrameworkBundle's own
        // LoggerAwareInterface pass, so the configured logger wins.
        $container->registerForAutoconfiguration(OpenIdLoginAuthenticator::class)
            ->addMethodCall('setLogger', [$logger]);
    }

    #[\Override]
    public function getAlias(): string
    {
        return 'itkdev_openid_connect';
    }
}
