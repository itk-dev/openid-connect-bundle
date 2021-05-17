<?php

namespace ItkDev\OpenIdConnectBundle\DependencyInjection;

use ItkDev\OpenIdConnectBundle\Controller\LoginController;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\FileLoader;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class ItkDevOpenIdConnectExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.xml');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $newConfig = [
            'urlConfiguration' => $config['open_id_provider_options']['configuration_url'],
            'clientId' => $config['open_id_provider_options']['client_id'],
            'clientSecret' => $config['open_id_provider_options']['client_secret'],
            'cachePath' => $config['open_id_provider_options']['cache_path'],
            'redirectUri' => $config['open_id_provider_options']['callback_uri'],
        ];

        $definition = $container->getDefinition('itkdev.openid_login_controller');
        $definition->replaceArgument('$openIdProviderOptions', $newConfig);
    }

    /**
     * {@inheritdoc}
     */
    public function getAlias()
    {
        return 'itkdev_openid_connect';
    }
}
