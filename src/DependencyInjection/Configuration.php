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
        $treeBuilder = new TreeBuilder('itk_dev_openid_connect');

        // Specify which variables must be configured in itk_dev_openid_connect file
        // That is client_id, client_secret, discovery url and cache path

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('openIdProviderOptions')
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
                ->scalarNode('default_uri')
                    ->defaultValue('https://127.0.0.1:8000')
                    ->info('Default URI, used for CLI login via URL')
                    ->isRequired()->cannotBeEmpty()->end()
            ->end();

        return $treeBuilder;
    }
}