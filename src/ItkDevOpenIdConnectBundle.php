<?php

namespace ItkDev\OpenIdConnectBundle;

use ItkDev\OpenIdConnectBundle\DependencyInjection\ItkDevOpenIdConnectExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Class ItkDevOpenIdConnectBundle
 */
class ItkDevOpenIdConnectBundle extends Bundle
{
    /**
     * {@inheritdoc}
     *
     * Overridden to allow for the custom extension alias.
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new ItkDevOpenIdConnectExtension();
        }

        return $this->extension;
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
