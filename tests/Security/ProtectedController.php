<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

use Symfony\Component\HttpFoundation\Response;

/**
 * Something behind the firewall for a request to be aimed at. Reaching it means
 * authentication succeeded, which no test here expects to happen.
 */
class ProtectedController
{
    public function __invoke(): Response
    {
        return new Response('authenticated');
    }
}
