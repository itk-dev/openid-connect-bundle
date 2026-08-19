<?php

namespace ItkDev\OpenIdConnectBundle\DependencyInjection;

use ItkDev\OpenIdConnectBundle\Log\AuthenticationAuditLogger;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
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
                            ->cannotBeEmpty()
                        ->end() // cache_pool
                    ->end()
                ->end() // cache_options
                ->arrayNode('cli_login_options')
                    ->isRequired()
                    ->children()
                        ->scalarNode('route')
                            ->info('Return route for CLI login')
                            ->isRequired()->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('user_provider')
                    ->defaultNull()
                    ->info('The User Provider to inject')
                ->end()
                ->arrayNode('logging_options')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('logger')
                            ->info('Service id of the PSR-3 logger to receive this bundle\'s failure logs, e.g. "monolog.logger.openid_connect". Defaults to the application logger, which Symfony always provides. Set "itkdev_openid_connect.null_logger" to turn logging off.')
                            ->defaultNull()
                            ->cannotBeEmpty()
                        ->end() // logger
                    ->end()
                ->end() // logging_options
                ->arrayNode('audit_options')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Write an authentication audit trail (logins, failures, CLI token issuance). Off by default: audit records identify people, so an existing installation must opt in rather than start logging personal data on upgrade.')
                            ->defaultFalse()
                        ->end() // enabled
                        ->scalarNode('logger')
                            ->info('Service id of the PSR-3 logger to receive audit records, e.g. "monolog.logger.openid_connect_audit". Defaults to the application logger. Keep this separate from logging_options.logger: an operational threshold of "error" would otherwise discard the whole trail.')
                            ->defaultNull()
                            ->cannotBeEmpty()
                        ->end() // logger
                        ->enumNode('identifier')
                            ->info('Record user identifiers as-is ("raw") or pseudonymised ("hashed"). Hashing is keyed with the application secret, so records still correlate.')
                            ->values([AuthenticationAuditLogger::IDENTIFIER_RAW, AuthenticationAuditLogger::IDENTIFIER_HASHED])
                            ->defaultValue(AuthenticationAuditLogger::IDENTIFIER_RAW)
                        ->end() // identifier
                    ->end()
                ->end() // audit_options
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
                                    ->integerNode('cache_duration')
                                        ->info('Cache duration in seconds for the OIDC discovery document and JWKS (default: 86400 — 24 hours)')
                                        ->defaultValue(86400)
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
                                    ->booleanNode('allow_http')
                                        ->info('Whether to allow http or not (default: false)')
                                        ->defaultValue(false)
                                    ->end()
                                    // Uses Guzzle under the hood through itk-dev/openid-connect -> league/oauth2-client -> guzzlehttp/guzzle
                                    ->arrayNode('http_client_options')
                                        ->info('Options forwarded to the underlying Guzzle HTTP client. league/oauth2-client only forwards: timeout, proxy, verify (verify is only consulted when proxy is set).')
                                        ->addDefaultsIfNotSet()
                                        ->children()
                                            // @see https://docs.guzzlephp.org/en/stable/request-options.html#timeout
                                            ->floatNode('timeout')
                                                ->info('Total request timeout in seconds. Defaults to 30; set to 0 to wait indefinitely (Guzzle\'s own default).')
                                                ->defaultValue(30.0)
                                            ->end()
                                            // @see https://docs.guzzlephp.org/en/stable/request-options.html#proxy
                                            ->scalarNode('proxy')
                                                ->info('HTTP proxy URI')
                                            ->end()
                                            // @see https://docs.guzzlephp.org/en/stable/request-options.html#verify
                                            ->booleanNode('verify')
                                                ->info('Verify TLS certificates (only consulted by Guzzle when proxy is set)')
                                            ->end()
                                        ->end()
                                    ->end()
                                ->end()
                                ->validate()
                                    ->ifTrue(static fn (array $v) => isset($v['redirect_uri'], $v['redirect_route']))
                                    ->thenInvalid('Only one of redirect_uri or redirect_route must be set.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
