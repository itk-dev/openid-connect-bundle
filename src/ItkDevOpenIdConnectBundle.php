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
     *
     * Returns a local rather than the property: Symfony 6.4 declares
     * `Bundle::$extension` untyped, so on the supported floor its value is `mixed` and
     * returning it directly does not satisfy this signature. 7.0 typed the property,
     * which is why analysing only against current dependencies never saw it.
     */
    #[\Override]
    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension instanceof ExtensionInterface) {
            return $this->extension;
        }

        // Reached when the property is null, or false as Symfony sets it for a bundle
        // with no extension — this bundle always has one.
        $extension = new ItkDevOpenIdConnectExtension();
        $this->extension = $extension;

        return $extension;
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
