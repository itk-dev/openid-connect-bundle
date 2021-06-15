<?php

namespace ItkDev\OpenIdConnectBundle\DependencyInjection;

use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Command\UserLoginCommand;
use ItkDev\OpenIdConnectBundle\Controller\LoginController;
use ItkDev\OpenIdConnectBundle\Security\LoginTokenAuthenticator;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
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
            'openIDConnectMetadataUrl' => $config['openid_provider_options']['configuration_url'],
            'clientId' => $config['openid_provider_options']['client_id'],
            'clientSecret' => $config['openid_provider_options']['client_secret'],
            'cacheItemPool' => new Reference($config['openid_provider_options']['cache_path']),
            'redirectUri' => $config['openid_provider_options']['callback_uri'],
        ];

        $definition = $container->getDefinition(OpenIdConfigurationProvider::class);
        $definition->replaceArgument('$options', $providerConfig);
        $definition->replaceArgument('$collaborators', []);

        $definition = $container->getDefinition(CliLoginHelper::class);
        $definition->replaceArgument('$cache', new Reference($config['cli_login_options']['cache_pool']));

        $definition = $container->getDefinition(UserLoginCommand::class);
        $definition->replaceArgument('$cliLoginRedirectRoute', $config['cli_login_options']['cli_redirect']);

        $definition = $container->getDefinition(LoginTokenAuthenticator::class);
        $definition->replaceArgument('$cliLoginRedirectRoute', $config['cli_login_options']['cli_redirect']);
    }

    /**
     * {@inheritdoc}
     */
    public function getAlias()
    {
        return 'itkdev_openid_connect';
    }
}
