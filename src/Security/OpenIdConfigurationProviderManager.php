<?php

namespace ItkDev\OpenIdConnectBundle\Security;

use ItkDev\OpenIdConnect\Exception\ItkOpenIdConnectException;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use ItkDev\OpenIdConnectBundle\Exception\InvalidProviderException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class OpenIdConfigurationProviderManager
{
    private ?array $providers;

    public function __construct(
        private readonly RouterInterface $router,
        private readonly array $config
    ) {
    }

    /**
     * Get all provider keys.
     *
     * @return array|string[]
     */
    public function getProviderKeys(): array
    {
        return array_keys($this->config['providers']);
    }

    /**
     * Get a provider by key.
     *
     * @throws InvalidProviderException
     * @throws ItkOpenIdConnectException
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
                    UrlGeneratorInterface::ABSOLUTE_URL
                );
            }

            if (isset($options['leeway'])) {
                $providerOptions['leeway'] = $options['leeway'];
            }

            $this->providers[$key] = new OpenIdConfigurationProvider($providerOptions);
        }

        if (isset($this->providers[$key])) {
            return $this->providers[$key];
        }

        throw new InvalidProviderException(sprintf('Invalid provider: %s', $key));
    }
}
