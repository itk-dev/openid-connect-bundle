<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Util;

use ItkDev\OpenIdConnectBundle\Util\ClientSecretExpiry;
use ItkDev\OpenIdConnectBundle\Util\ClientSecretExpiryStatus;
use PHPUnit\Framework\TestCase;

class ClientSecretExpiryTest extends TestCase
{
    public function testExpiresAtForHumansFormatsTheDate(): void
    {
        $expiry = new ClientSecretExpiry(
            'azure',
            ClientSecretExpiryStatus::Expired,
            new \DateTimeImmutable('2026-07-01 00:00:00', new \DateTimeZone('UTC')),
            -50,
        );

        $this->assertSame('2026-07-01', $expiry->expiresAtForHumans());
    }

    public function testExpiresAtForHumansWithoutADate(): void
    {
        // The checker never pairs a status with a missing date, but this class
        // does not enforce that, so the message stays sensible either way.
        $expiry = new ClientSecretExpiry('azure', ClientSecretExpiryStatus::Unknown);

        $this->assertSame('an unknown date', $expiry->expiresAtForHumans());
    }
}
