<?php

namespace ItkDev\OpenIdConnectBundle;

use ItkDev\OpenIdConnectBundle\DependencyInjection\ItkDevOpenIdConnectExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class ItkDevOpenIdConnectBundle extends Bundle
{
    public function getContainerExtension()
    {
        return new ItkDevOpenIdConnectExtension();
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}