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
                        ->end() // cache_pool
                    ->end()
                ->end() // cache_options
                ->arrayNode('cli_login_options')
                    ->isRequired()
                    ->children()
                        ->scalarNode('cli_redirect')
                            ->info('Return route for CLI login')
                            ->isRequired()->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('openid_providers')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('options')
                                ->isRequired()
                                ->children()
                                    ->scalarNode('metadata_url')
                                        ->info('URL to OpenId Discovery Document')
                                        ->isRequired()
                                    ->end()
                                    ->scalarNode('client_id')
                                        ->info('Client ID assigned by authorizer')
                                        ->isRequired()->cannotBeEmpty()
                                    ->end()
                                    ->scalarNode('client_secret')
                                        ->info('Client secret/password assigned by authorizer')
                                        ->isRequired()->cannotBeEmpty()
                                    ->end()
                                    ->integerNode('leeway')
                                        ->info('Leeway in seconds to account for clock skew between server and provider')
                                        ->defaultValue(10)
                                    ->end()
                                    ->scalarNode('redirect_uri')
                                        ->info('Redirect URI registered at identity provider')
                                        ->cannotBeEmpty()
                                    ->end()
                                    ->scalarNode('redirect_route')
                                        ->info('Redirect route registered at identity provider (must not be set if redirect_uri is set)')
                                        ->cannotBeEmpty()
                                    ->end()
                                    ->arrayNode('redirect_route_parameters')
                                        ->info('Redirect route parameters')
                                    ->end()
                                ->end()
                                ->validate()
                                    ->always()
                                    ->then(
                                        static function (array $value) {
                                            // Complain if both redirect_uri and redirect_route are set.
                                            if (isset($value['redirect_uri'], $value['redirect_route'])) {
                                                throw new \InvalidArgumentException('Only one of redirect_uri or redirect_route must be set.');
                                            }

                                            return $value;
                                        }
                                    )
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
