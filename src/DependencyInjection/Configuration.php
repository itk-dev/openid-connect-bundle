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
        // That is a table name for login tokens,
        // client_id, client_secret, discovery url, cache path and callback uri

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('cache_pool')
                    ->info('Method for caching')
                    ->defaultValue('cache.app')
                    ->cannotBeEmpty()->end()
                ->arrayNode('open_id_provider_options')
                    ->isRequired()
                    ->children()
                        ->scalarNode('configuration_url')
                            ->info('URL to OpenId Discovery Document')
                            ->validate()
                                ->ifTrue(
                                    function ($value) {
                                        return !filter_var($value, FILTER_VALIDATE_URL);
                                    }
                                )
                                ->thenInvalid('Invalid URL given.')
                            ->end()
                            ->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('client_id')
                            ->info('Client ID assigned by authorizer')
                            ->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('client_secret')
                            ->info('Client secret/password assigned by authorizer')
                            ->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('cache_path')
                            ->info('Path for caching Discovery document')
                            ->defaultValue('%kernel.cache_dir%/openid_connect_configuration_cache.php')
                            ->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('callback_uri')
                            ->info('Callback URI registered at identity provider')
                            ->validate()
                                ->ifTrue(
                                    function ($value) {
                                        return !filter_var($value, FILTER_VALIDATE_URL);
                                    }
                                )
                                ->thenInvalid('Invalid URL given.')
                            ->end()
                            ->isRequired()->cannotBeEmpty()->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
