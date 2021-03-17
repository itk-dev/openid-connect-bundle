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
        $treeBuilder = new TreeBuilder('itk_dev_open_id_connect');

        // Specify which variables must be configured in itk_dev_openid_connect file
        // That is client_id, client_secret, discovery url and cache path
        // And return route for redirect uri generating in loginController

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('open_id_provider_options')
                    ->isRequired()
                    ->children()
                        ->scalarNode('open_id_provider_url')
                            ->info('URL to OpenId Discovery Document')
                            ->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('open_id_provider_client_id')
                            ->info('Client ID assigned by authorizer')
                            ->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('open_id_provider_client_secret')
                            ->info('Client secret/password assigned by authorizer')
                            ->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('open_id_provider_cache_path')
                            ->info('Path for caching Discovery document')
                            ->defaultValue('%kernel.cache_dir%/.well_known_cache.php')
                            ->isRequired()->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->scalarNode('open_id_return_route')
                    ->info('Return route for authorizer')
                    ->isRequired()->cannotBeEmpty()->end()
            ->end();

        return $treeBuilder;
    }
}