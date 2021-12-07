<?php

namespace ItkDev\OpenIdConnectBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('itkdev_openid_connect');

        // Specify which variables must be configured in itk_dev_openid_connect file
        // That is client_id, client_secret, discovery url and cache path
        // And return route for redirect uri generating in loginController

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('cache_options')
                    ->isRequired()
                    ->children()
                        ->scalarNode('cache_pool')
                            ->info('Method for caching')
                            ->defaultValue('cache.app')
                            ->isRequired()->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('cli_login_options')
                    ->isRequired()
                    ->children()
                        ->scalarNode('cli_redirect')
                            ->info('Return route for CLI login')
                            ->isRequired()->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
