<?php

namespace ItkDev\OpenIdConnectBundle\Exception;

use ItkDev\OpenIdConnect\Exception\OpenIdConnectExceptionInterface;

/**
 * Marker interface for every exception thrown from a public method of this bundle.
 *
 * Extends the upstream library marker so a consumer can catch every OIDC failure
 * from both packages with a single `catch (OpenIdConnectExceptionInterface $e)`,
 * or scope to bundle-only failures with `catch (OpenIdConnectBundleExceptionInterface $e)`.
 */
interface OpenIdConnectBundleExceptionInterface extends OpenIdConnectExceptionInterface
{
}
