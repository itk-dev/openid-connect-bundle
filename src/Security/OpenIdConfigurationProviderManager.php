<?php

namespace ItkDev\OpenIdConnectBundle\Security;

use Firebase\JWT\JWT;
use GuzzleHttp\Exception\GuzzleException;
use ItkDev\OpenIdConnect\Exception\BadUrlException;
use ItkDev\OpenIdConnect\Exception\CacheException;
use ItkDev\OpenIdConnect\Exception\ClaimsException;
use ItkDev\OpenIdConnect\Exception\DecodeException;
use ItkDev\OpenIdConnect\Exception\HttpException;
use ItkDev\OpenIdConnect\Exception\IllegalSchemeException;
use ItkDev\OpenIdConnect\Exception\ItkOpenIdConnectException;
use ItkDev\OpenIdConnect\Exception\JsonException;
use ItkDev\OpenIdConnect\Exception\KeyException;
use ItkDev\OpenIdConnect\Exception\MissingParameterException;
use ItkDev\OpenIdConnect\Exception\NegativeLeewayException;
use ItkDev\OpenIdConnect\Exception\ValidationException;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Exception\InvalidProviderException;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericResourceOwner;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Tool\RequestFactory;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Routing\RouterInterface;

class OpenIdConfigurationProviderManager
{
    /**
     * @var array
     */
    private $config;

    /**
     * @var array
     */
    private $providers;

    /**
     * @var RouterInterface
     */
    private $router;

    public function __construct(RouterInterface $router, array $config)
    {
        $this->router = $router;
        $this->config = $config;
    }

    /**
     * Get all provider keys.
     *
     * @return array|OpenIdConfigurationProvider[]
     * @throws ItkOpenIdConnectException
     */
    public function getProviderKeys(): array
    {
        return array_keys($this->config['providers']);
    }

    /**
     * Get a provider by key.
     *
     * @param string $key
     * @return OpenIdConfigurationProvider
     * @throws InvalidProviderException
     */
    public function getProvider(string $key): OpenIdConfigurationProvider
    {
        if (!isset($this->providers[$key]) && isset($this->config['providers'][$key])) {
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
                    RouterInterface::ABSOLUTE_URL
                );
            }

            $this->providers[$key] = new OpenIdConfigurationProvider($providerOptions);
        }

        if (isset($this->providers[$key])) {
            return $this->providers[$key];
        }

        throw new InvalidProviderException(sprintf('Invalid provider: %s', $key));
    }
}
