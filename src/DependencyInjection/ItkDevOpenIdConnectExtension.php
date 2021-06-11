<?php

namespace ItkDev\OpenIdConnectBundle\DependencyInjection;

use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Controller\LoginController;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\FileLoader;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

class ItkDevOpenIdConnectExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $providerConfig = [
            'openIDConnectMetadataUrl' => $config['open_id_provider_options']['configuration_url'],
            'clientId' => $config['open_id_provider_options']['client_id'],
            'clientSecret' => $config['open_id_provider_options']['client_secret'],
            'cacheItemPool' => new Reference($config['open_id_provider_options']['cache_path']),
            'redirectUri' => $config['open_id_provider_options']['callback_uri'],
        ];

        $definition = $container->getDefinition(OpenIdConfigurationProvider::class);
        $definition->replaceArgument('$options', $providerConfig);
        $definition->replaceArgument('$collaborators', []);
    }

    /**
     * {@inheritdoc}
     */
    public function getAlias()
    {
        return 'itkdev_openid_connect';
    }
}
