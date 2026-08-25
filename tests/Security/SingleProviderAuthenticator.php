<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

/**
 * A consumer authenticator bound to one provider, as an application with one
 * authenticator per provider would write it.
 */
class SingleProviderAuthenticator extends TestAuthenticator
{
    #[\Override]
    protected function getSupportedProviderKeys(): array
    {
        return ['test_provider_1'];
    }
}
