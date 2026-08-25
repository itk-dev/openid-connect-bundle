<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * A request with a base URL of our choosing.
 *
 * `getBaseUrl()` is otherwise derived from `SCRIPT_NAME` and friends, and the trusted
 * proxy half of it needs process-wide state (`Request::setTrustedProxies()`). Both
 * produce the same thing as far as this bundle is concerned — a base URL that
 * `getPathInfo()` does not include — so overriding it keeps the tests free of global
 * state while exercising the case that matters.
 */
class RequestWithBaseUrl extends Request
{
    /**
     * @param array<string, string> $query
     */
    public function __construct(private readonly string $overriddenBaseUrl, array $query = [])
    {
        parent::__construct($query);
    }

    #[\Override]
    public function getBaseUrl(): string
    {
        return $this->overriddenBaseUrl;
    }

    #[\Override]
    public function getPathInfo(): string
    {
        return '/callback_uri';
    }
}
