<?php

namespace ItkDev\OpenIdConnectBundle\Security;

use GuzzleHttp\Client as GuzzleClient;
use ItkDev\OpenIdConnect\Exception\OpenIdConnectExceptionInterface;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Exception\InvalidProviderException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class OpenIdConfigurationProviderManager
{
    /**
     * One HTTP client per provider, for the life of the process.
     *
     * The client owns the connection pool, so sharing it across logins means a token
     * exchange reuses an open connection to the identity provider rather than
     * renegotiating TLS. Its options come from configuration and never change, and it
     * holds nothing belonging to a request, so it is safe to share however long the
     * process lives.
     *
     * @var array<string, GuzzleClient>
     */
    private array $httpClients = [];

    /**
     * Callback paths, keyed by the routing context's base URL.
     *
     * Safe to hold across requests: the values are a pure function of configuration
     * and that base URL, so two requests sharing a key derive identical paths. The
     * key set is bounded by the number of base URLs an application answers on, which
     * is one in every deployment shape the bundle documents.
     *
     * @var array<string, array<string, string>>
     */
    private array $redirectUriPaths = [];

    /**
     * @param array{
     *     default_providers_options: array{cacheItemPool?: CacheItemPoolInterface},
     *     providers: array<string, array{
     *         metadata_url: string,
     *         client_id: string,
     *         client_secret: string,
     *         redirect_uri?: string,
     *         redirect_route?: string,
     *         redirect_route_parameters?: array<string, string>,
     *         callback_path?: string,
     *         leeway?: int,
     *         cache_duration?: int,
     *         pkce?: bool,
     *         scopes?: string[],
     *         allow_http?: bool,
     *         http_client_options?: array{
     *             timeout?: float,
     *             proxy?: string,
     *             verify?: bool,
     *         },
     *     }>,
     * } $config
     */
    public function __construct(
        private readonly RouterInterface $router,
        private readonly array $config,
    ) {
    }

    /**
     * Get all provider keys.
     *
     * @return string[]
     */
    public function getProviderKeys(): array
    {
        return array_keys($this->config['providers']);
    }

    /**
     * The scopes this provider's authorization request asks for.
     *
     * Read from configuration, like the callback paths: the caller needs the answer
     * before it has any use for a provider.
     *
     * An unconfigured key answers the same default the config tree applies, keeping
     * one rule in two places from drifting apart.
     *
     * @return string[]
     */
    public function getScopes(string $providerKey): array
    {
        return $this->config['providers'][$providerKey]['scopes'] ?? ['openid', 'email', 'profile'];
    }

    /**
     * Whether this provider's authorization request carries a PKCE challenge.
     *
     * Read from configuration, like the callback paths: the caller needs the answer
     * before it has any use for a provider.
     *
     * An unconfigured key answers true. The config tree makes that unreachable, since
     * `pkce` defaults to true there, and matching it here keeps one rule in two places
     * from drifting apart.
     */
    public function isPkceEnabled(string $providerKey): bool
    {
        return $this->config['providers'][$providerKey]['pkce'] ?? true;
    }

    /**
     * The request path each provider's callback arrives on, keyed by provider.
     *
     * Derived from configuration rather than from a provider instance:
     * `supports()` consults this on every request through the firewall, and
     * building a provider pulls in discovery, HTTP client and cache configuration
     * for no reason. Memoized for the same reason.
     *
     * @return array<string, string>
     */
    public function getRedirectUriPaths(): array
    {
        // Keyed by the routing context's base URL, not memoized flat: a generated
        // route includes that base URL, and it differs between a proxied request
        // carrying X-Forwarded-Prefix and a direct one. One frozen map could only
        // ever match one of them.
        $memoKey = $this->router->getContext()->getBaseUrl();

        if (isset($this->redirectUriPaths[$memoKey])) {
            return $this->redirectUriPaths[$memoKey];
        }

        $paths = [];

        foreach ($this->config['providers'] as $key => $options) {
            $path = $this->derivePath($options);

            if (null !== $path) {
                $paths[$key] = $path;
            }
        }

        return $this->redirectUriPaths[$memoKey] = $paths;
    }

    /**
     * Whether a request path is the callback path of a given provider.
     *
     * Takes the path as `$request->getBaseUrl().$request->getPathInfo()`, which is
     * what lines up with every derivation. `getPathInfo()` alone does not:
     * `Request::preparePathInfo()` strips `getBaseUrlReal()`, so it excludes both a
     * subdirectory deployment's base path and any trusted `X-Forwarded-Prefix` —
     * while a `redirect_uri`'s path contains the prefix as the identity provider sees
     * it, and `UrlGenerator` prepends the routing context's base URL, which includes
     * the trusted prefix.
     */
    public function isCallbackPath(string $requestPath, string $providerKey): bool
    {
        $paths = $this->getRedirectUriPaths();

        if (!isset($paths[$providerKey])) {
            return false;
        }

        // Case-sensitive: paths are, and an identity provider sends the browser to
        // the redirect URI exactly as it was registered.
        return $paths[$providerKey] === $this->normalizePath($requestPath);
    }

    /**
     * @param array{redirect_uri?: string, redirect_route?: string, redirect_route_parameters?: array<string, string>, callback_path?: string} $options
     */
    private function derivePath(array $options): ?string
    {
        // callback_path first: it exists precisely for deployments where the
        // external redirect_uri path is not the path this application receives.
        // '' passes configuration on purpose — it is the fixture Symfony substitutes
        // for a string environment variable while compiling — so an environment
        // variable that resolves to nothing arrives here. Normalizing it would make
        // the site root the callback path and shadow redirect_uri.
        if (isset($options['callback_path']) && '' !== $options['callback_path']) {
            return $this->normalizePath($options['callback_path']);
        }

        // Generated as a path, not a URL, so a reverse proxy's prefix handling is
        // already accounted for by the router.
        if (isset($options['redirect_route'])) {
            return $this->normalizePath($this->router->generate(
                $options['redirect_route'],
                $options['redirect_route_parameters'] ?? [],
                UrlGeneratorInterface::ABSOLUTE_PATH
            ));
        }

        if (isset($options['redirect_uri'])) {
            // An external URL: its path is what the identity provider sends the
            // browser to, which is the internal path only when nothing rewrites it.
            $path = parse_url($options['redirect_uri'], \PHP_URL_PATH);

            // A redirect_uri with no path at all, or one that could not be parsed:
            // the provider then answers at the application root.
            return $this->normalizePath(is_string($path) ? $path : '/');
        }

        return null;
    }

    /**
     * Leading slash, no trailing slash, so that the comparison in `supports()`
     * does not turn on how the value was written.
     */
    private function normalizePath(string $path): string
    {
        $trimmed = rtrim('/'.ltrim($path, '/'), '/');

        return '' === $trimmed ? '/' : $trimmed;
    }

    /**
     * Get a provider by key.
     *
     * A fresh instance every call. A provider belongs to one request:
     * `league/oauth2-client` assigns `$this->state` on every `getAuthorizationUrl()`
     * call, so an instance shared between requests holds the previous one's state.
     *
     * Constructing one is cheap. Discovery and JWKS are lazy and cached in the PSR-6
     * pool, and the costly collaborator — the HTTP client, with its connection pool —
     * comes from `$httpClients`.
     *
     * @throws OpenIdConnectExceptionInterface
     */
    public function getProvider(string $key): OpenIdConfigurationProvider
    {
        if (isset($this->config['providers'][$key])) {
            $options = $this->config['providers'][$key];
            $providerOptions = [
                'openIDConnectMetadataUrl' => $options['metadata_url'],
                'clientId' => $options['client_id'],
                'clientSecret' => $options['client_secret'],
            ] + $this->config['default_providers_options'];

            if (isset($options['redirect_uri'])) {
                $providerOptions['redirectUri'] = $options['redirect_uri'];
            } elseif (isset($options['redirect_route'])) {
                $providerOptions['redirectUri'] = $this->router->generate(
                    $options['redirect_route'],
                    $options['redirect_route_parameters'] ?? [],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );
            }

            if (isset($options['leeway'])) {
                $providerOptions['leeway'] = $options['leeway'];
            }

            if (isset($options['cache_duration'])) {
                $providerOptions['cacheDuration'] = $options['cache_duration'];
            }

            if (isset($options['allow_http'])) {
                $providerOptions['allowHttp'] = $options['allow_http'];
            }

            if (isset($options['http_client_options']) && [] !== $options['http_client_options']) {
                $providerOptions += $options['http_client_options'];
            }

            return new OpenIdConfigurationProvider(
                $providerOptions,
                ['httpClient' => $this->httpClient($key, $providerOptions)],
            );
        }

        throw new InvalidProviderException(sprintf('Invalid provider: %s', $key));
    }

    /**
     * The HTTP client for a provider, built once and shared by its providers.
     *
     * `league/oauth2-client` builds a client per provider instance, which would mean
     * a new connection pool per request; building it here gives every provider for a
     * key the same one.
     *
     * The option filter mirrors `AbstractProvider::getAllowedClientOptions()`, so the
     * client is configured exactly as league configures its own: `timeout` and
     * `proxy` always, `verify` only alongside a proxy — league's rule that TLS
     * verification may be relaxed for a proxy and nowhere else.
     *
     * @param array<string, mixed> $providerOptions
     */
    private function httpClient(string $key, array $providerOptions): GuzzleClient
    {
        if (isset($this->httpClients[$key])) {
            return $this->httpClients[$key];
        }

        $allowed = ['timeout', 'proxy'];

        // `proxy` is a scalar node, so it arrives as a string or not at all; an empty
        // one names no proxy. This matches league's own `empty()` test for every
        // value the config tree can produce.
        if (isset($providerOptions['proxy']) && '' !== $providerOptions['proxy']) {
            $allowed[] = 'verify';
        }

        return $this->httpClients[$key] = new GuzzleClient(
            array_intersect_key($providerOptions, array_flip($allowed))
        );
    }
}
