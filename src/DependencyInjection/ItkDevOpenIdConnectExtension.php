<?php

namespace ItkDev\OpenIdConnectBundle\DependencyInjection;

use Exception;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Command\UserLoginCommand;
use ItkDev\OpenIdConnectBundle\Controller\LoginController;
use ItkDev\OpenIdConnectBundle\Security\LoginTokenAuthenticator;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

class ItkDevOpenIdConnectExtension extends Extension
{
    /**
     * {@inheritdoc}
     * @throws Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $definition = $container->getDefinition(CliLoginHelper::class);
        $definition->replaceArgument('$cache', new Reference($config['cache_options']['cache_pool']));

        $definition = $container->getDefinition(UserLoginCommand::class);
        $definition->replaceArgument('$cliLoginRedirectRoute', $config['cli_login_options']['cli_redirect']);

        $definition = $container->getDefinition(LoginTokenAuthenticator::class);
        $definition->replaceArgument('$cliLoginRedirectRoute', $config['cli_login_options']['cli_redirect']);
    }

    /**
     * {@inheritdoc}
     */
    public function getAlias(): string
    {
        return 'itkdev_openid_connect';
    }
}
