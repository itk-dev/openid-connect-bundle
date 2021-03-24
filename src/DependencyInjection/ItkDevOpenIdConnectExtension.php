<?php

namespace ItkDev\OpenIdConnectBundle\DependencyInjection;

use ItkDev\OpenIdConnectBundle\Controller\LoginController;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class ItkDevOpenIdConnectExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__).'/../config'));
        $loader->load('services.yaml');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $newConfig = [
            'urlConfiguration' => $config['open_id_provider_options']['configuration_url'],
            'clientId' => $config['open_id_provider_options']['client_id'],
            'clientSecret' => $config['open_id_provider_options']['client_secret'],
            'cachePath' => $config['open_id_provider_options']['cache_path'],
            'redirectUri' => $config['open_id_provider_options']['callback_uri'],
        ];

        $definition = $container->getDefinition(LoginController::class);
        $definition->replaceArgument('$openIdProviderOptions', $newConfig);
    }
}
