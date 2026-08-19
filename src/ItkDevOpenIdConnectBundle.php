<?php

namespace ItkDev\OpenIdConnectBundle;

use ItkDev\OpenIdConnectBundle\DependencyInjection\Compiler\ConfiguredLoggerPass;
use ItkDev\OpenIdConnectBundle\DependencyInjection\ItkDevOpenIdConnectExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Class ItkDevOpenIdConnectBundle.
 */
class ItkDevOpenIdConnectBundle extends Bundle
{
    /**
     * {@inheritdoc}
     *
     * Overridden to allow for the custom extension alias.
     */
    #[\Override]
    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension || false === $this->extension) {
            $this->extension = new ItkDevOpenIdConnectExtension();
        }

        return $this->extension;
    }

    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Runs after the instanceof conditionals it has to correct, and does nothing
        // unless the extension recorded a configured logger.
        $container->addCompilerPass(new ConfiguredLoggerPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    #[\Override]
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
