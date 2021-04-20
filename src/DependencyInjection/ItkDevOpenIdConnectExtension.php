<?php

namespace ItkDev\OpenIdConnectBundle\DependencyInjection;

use ItkDev\OpenIdConnectBundle\Command\UserLoginCommand;
use ItkDev\OpenIdConnectBundle\Controller\LoginController;
use ItkDev\OpenIdConnectBundle\Security\LoginTokenAuthenticator;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

class ItkDevOpenIdConnectExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__).'/../config'));
        $loader->load('services.yaml');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $providerConfig = [
            'urlConfiguration' => $config['open_id_provider_options']['configuration_url'],
            'clientId' => $config['open_id_provider_options']['client_id'],
            'clientSecret' => $config['open_id_provider_options']['client_secret'],
            'cachePath' => $config['open_id_provider_options']['cache_path'],
            'redirectUri' => $config['open_id_provider_options']['callback_uri'],
        ];

        $definition = $container->getDefinition(LoginController::class);
        $definition->replaceArgument('$openIdProviderOptions', $providerConfig);

        $definition = $container->getDefinition(CliLoginHelper::class);
        $definition->addArgument(new Reference($config['cache_pool']));

        $definition = $container->getDefinition(UserLoginCommand::class);
        $definition->replaceArgument('$cliLoginRedirectRoute', $config['cli_redirect']);

        $definition = $container->getDefinition(LoginTokenAuthenticator::class);
        $definition->replaceArgument('$cliLoginRedirectRoute', $config['cli_redirect']);
    }

    public function getAlias(): string
    {
        return  'itk_dev_openid_connect';
    }
}
