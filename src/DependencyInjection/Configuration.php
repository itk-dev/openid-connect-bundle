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
                        // Deliberately NOT settable from an environment variable, and
                        // an enumNode enforces that by refusing one. The extension
                        // picks the HMAC key while the container compiles, so it has
                        // to know the mode then; an environment variable would leave
                        // it comparing against an unresolved placeholder and quietly
                        // hashing with an empty key, which is worse than not hashing
                        // at all because it looks pseudonymised. To vary this per
                        // environment use Symfony's `when@prod:` blocks, which are
                        // resolved at compile time and work here.
                        ->enumNode('identifier')
                            ->info('Record user identifiers as-is ("raw") or pseudonymised ("hashed"). Hashing is keyed with the application secret, so records still correlate. Cannot come from an environment variable; use environment-specific configuration instead.')
                            ->values([AuthenticationAuditLogger::IDENTIFIER_RAW, AuthenticationAuditLogger::IDENTIFIER_HASHED])
                            ->defaultValue(AuthenticationAuditLogger::IDENTIFIER_RAW)
                        ->end() // identifier
                    ->end()
                ->end() // audit_options
                ->arrayNode('secret_expiry_options')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('warning_days')
                            ->info('How many days before a client secret expires the bundle starts warning (default: 30)')
                            ->defaultValue(30)
                            ->min(0)
                        ->end() // warning_days
                    ->end()
                ->end() // secret_expiry_options
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
                                    ->scalarNode('client_secret_expires_at')
                                        // No cannotBeEmpty() here, and it cannot come back:
                                        // VariableNode::finalizeValue() refuses an environment variable
                                        // whenever empty values are disallowed and the node has any
                                        // validation closure, without looking at what the closure does.
                                        // On a node fed from the environment the two are mutually
                                        // exclusive, and the closure is the half worth keeping — a
                                        // mistyped literal is the realistic mistake, while the empties
                                        // cannotBeEmpty() would have caught are reported at runtime by
                                        // ClientSecretExpiryChecker. It never caught whitespace-only
                                        // values regardless: ScalarNode::isValueEmpty() is
                                        // `null === $value || '' === $value`.
                                        ->info('Optional. Date the client secret expires, e.g. "2027-01-31". Anything strtotime() understands, and usually an environment variable. Set it and the bundle warns before the secret expires; leave it unset and the provider reports "unknown" and is not monitored. Set it where the real secret lives — a date carried in a committed default is a date nobody maintains.')
                                        ->validate()
                                            // YAML reads an unquoted 2027-01-31 as the integer 1801353600, and the
                                            // closure below only inspects strings, so without this the most natural
                                            // way to write the value would pass, be discarded as untyped, and leave
                                            // the provider unmonitored with nothing logged. Leaving the key out
                                            // entirely is a decision and reports "unknown"; writing a value that
                                            // cannot be one is a mistake, including an explicit null.
                                            ->ifTrue(static fn (mixed $v): bool => !is_string($v))
                                            ->thenInvalid('client_secret_expires_at must be a string. YAML reads an unquoted date as a number, so quote it: "2027-01-31". From an environment variable, cast it as %%env(string:NAME)%%. Got %s.')
                                        ->end()
                                        ->validate()
                                            // '' is exempt because it is the dummy fixture Symfony
                                            // substitutes for %env(string:...)% while compiling
                                            // (ValidateEnvPlaceholdersPass::TYPE_FIXTURES), so rejecting
                                            // it here would reject every environment variable. Only that
                                            // exact value is exempt — a whitespace-only literal is a typo
                                            // and fails here, while a whitespace-only *environment* value
                                            // is caught at runtime by the checker. Env var contents can
                                            // never be validated at compile time; what this catches is a
                                            // typo in a literal date.
                                            // The trim() check is not redundant: strtotime('   ') returns
                                            // a timestamp rather than false, the same "blank means now"
                                            // quirk DateTimeImmutable has, so whitespace would otherwise
                                            // sail through as a valid date.
                                            // is_string() is unreachable-looking now that the closure above
                                            // rejects non-strings, but it is what lets trim() and strtotime()
                                            // take a mixed value under PHPStan, and it keeps this closure
                                            // correct on its own terms rather than by ordering.
                                            ->ifTrue(static fn (mixed $v): bool => is_string($v) && '' !== $v && ('' === trim($v) || false === strtotime($v)))
                                            ->thenInvalid('client_secret_expires_at must be a date parseable by strtotime(), e.g. "2027-01-31". Got %s.')
                                        ->end()
                                    ->end()
                                    ->integerNode('leeway')
                                        ->info('Leeway in seconds to account for clock skew between server and provider')
                                        ->defaultValue(10)
                                        // A window, so zero means no window. A negative one is
                                        // rejected here rather than at the first login it breaks.
                                        ->min(0)
                                    ->end()
                                    ->integerNode('cache_duration')
                                        ->info('Cache duration in seconds for the OIDC discovery document and JWKS (default: 86400 — 24 hours)')
                                        ->defaultValue(86400)
                                        // Zero fetches the discovery document every time, which is
                                        // wasteful but coherent. A negative lifetime is not.
                                        ->min(0)
                                    ->end()
                                    ->arrayNode('scopes')
                                        ->info('Scopes requested from the identity provider (default: openid, email, profile)')
                                        ->scalarPrototype()->end()
                                        ->defaultValue(['openid', 'email', 'profile'])
                                        ->requiresAtLeastOneElement()
                                        // Accept a space-separated string so the list can come from
                                        // an environment variable, which can only carry a scalar.
                                        ->beforeNormalization()
                                            ->ifString()
                                            ->then(static function (string $scopes): array {
                                                // NO_EMPTY drops the empty strings a
                                                // leading or trailing space produces,
                                                // so surrounding whitespace needs no
                                                // separate trim.
                                                $split = preg_split('/\s+/', $scopes, -1, PREG_SPLIT_NO_EMPTY);

                                                // preg_split only fails on a malformed
                                                // pattern; this one is a literal. An
                                                // empty list is caught below.
                                                return false === $split ? [] : $split;
                                            })
                                        ->end()
                                        ->validate()
                                            // OpenID Connect Core 1.0 §3.1.2.1: an authentication
                                            // request is one that asks for `openid`. Without it the
                                            // provider answers with a plain OAuth2 grant and no ID
                                            // token, and every check this bundle makes needs one.
                                            ->ifTrue(static fn (array $scopes): bool => !in_array('openid', $scopes, true))
                                            ->thenInvalid('scopes must include openid: without it the provider returns no ID token.')
                                        ->end()
                                    ->end()
                                    ->booleanNode('pkce')
                                        // On by default: RFC 6749 §3.1 requires an authorization
                                        // server to ignore parameters it does not recognise, so a
                                        // challenge costs nothing at an identity provider that does
                                        // not support PKCE. Turn it off for one that rejects
                                        // unknown parameters outright.
                                        ->info('Send a PKCE challenge (RFC 7636, S256) with the authorization request')
                                        ->defaultTrue()
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
                                    ->scalarNode('callback_path')
                                        ->info('Optional. The request path the callback arrives on, for a proxy that rewrites it without sending X-Forwarded-Prefix. Include any base path. Defaults to the path of redirect_uri, or of the generated redirect_route; a trusted X-Forwarded-Prefix or a subdirectory deployment is already accounted for without this.')
                                        // As on client_secret_expires_at: a validated node that also
                                        // disallows empty values refuses environment variables, and the
                                        // closure is the half worth keeping.
                                        ->validate()
                                            ->ifTrue(static fn (mixed $v): bool => !is_string($v))
                                            ->thenInvalid('callback_path must be a string, e.g. "/auth/callback". Got %s.')
                                        ->end()
                                        ->validate()
                                            // '' is the fixture Symfony substitutes for a string
                                            // environment variable while compiling, so it has to pass.
                                            ->ifTrue(static fn (mixed $v): bool => is_string($v) && '' !== $v && !str_starts_with($v, '/'))
                                            ->thenInvalid('callback_path must start with "/", e.g. "/auth/callback". Got %s.')
                                        ->end()
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
                                ->validate()
                                    // Without one of these there is no path to recognise a callback on,
                                    // and since 6.0 that means the provider can never complete a login:
                                    // supports() matches the configured callback path, not any path
                                    // carrying state and code.
                                    ->ifTrue(static fn (array $v) => !isset($v['redirect_uri']) && !isset($v['redirect_route']) && !isset($v['callback_path']))
                                    ->thenInvalid('One of redirect_uri, redirect_route or callback_path must be set: it is how a callback is recognised.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
