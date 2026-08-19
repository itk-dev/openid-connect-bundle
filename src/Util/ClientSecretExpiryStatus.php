<?php

namespace ItkDev\OpenIdConnectBundle\Util;

/**
 * How a provider's client secret stands relative to its expiry date.
 */
enum ClientSecretExpiryStatus: string
{
    /**
     * No expiry date configured, so nothing can be said.
     *
     * This is the state every installation is in until it sets
     * `client_secret_expires_at`, which is why it is distinct from `Ok` rather
     * than optimistically folded into it.
     */
    case Unknown = 'unknown';

    case Ok = 'ok';

    case ExpiringSoon = 'expiring_soon';

    case Expired = 'expired';
}
