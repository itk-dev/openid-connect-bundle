<?php

namespace ItkDev\OpenIdConnectBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class ItkDevOpenIdConnectBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}