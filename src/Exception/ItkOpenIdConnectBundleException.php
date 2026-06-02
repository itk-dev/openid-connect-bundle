<?php

namespace ItkDev\OpenIdConnectBundle\Exception;

/**
 * @deprecated since 5.0, will be removed in 6.0.
 *             Catch {@see OpenIdConnectBundleExceptionInterface} instead. Concrete bundle
 *             exceptions no longer extend this class; they extend the SPL exception that best
 *             describes the failure category and implement the marker interface.
 */
abstract class ItkOpenIdConnectBundleException extends \Exception implements OpenIdConnectBundleExceptionInterface
{
}
