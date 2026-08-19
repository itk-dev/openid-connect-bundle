<?php

namespace ItkDev\OpenIdConnectBundle\Util;

use Psr\Clock\ClockInterface;

/**
 * Answers how close each provider's client secret is to expiring.
 *
 * An expired client secret takes a site down completely — the token exchange
 * starts failing with `invalid_client` and every login breaks — and nothing about
 * it is visible until it happens. Knowing the expiry date is what turns that from
 * an outage into a calendar item, so the date is configuration
 * (`client_secret_expires_at`) and this service is what reads it.
 *
 * The clock is injected so "how many days left" is testable at the boundaries
 * rather than only on the day it matters.
 */
class ClientSecretExpiryChecker
{
    /**
     * Both arguments are always supplied by the extension; the default warning
     * window lives in the configuration, not here, so the value is not duplicated.
     *
     * @param array<string, string|null> $expiryDates providerKey => configured date, or null when unset
     */
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly array $expiryDates,
        private readonly int $warningDays,
    ) {
    }

    /**
     * @return string[]
     */
    public function getProviderKeys(): array
    {
        return array_keys($this->expiryDates);
    }

    public function getStatus(string $providerKey): ClientSecretExpiry
    {
        $configured = $this->expiryDates[$providerKey] ?? null;

        if (null === $configured) {
            return new ClientSecretExpiry($providerKey, ClientSecretExpiryStatus::Unknown);
        }

        // The date format is validated when the container compiles, so by here it
        // parses. Anchored to midnight UTC: a date-only value like "2027-01-31"
        // otherwise inherits the current time of day, which would make
        // daysRemaining drift depending on when the check ran.
        $expiresAt = new \DateTimeImmutable($configured, new \DateTimeZone('UTC'));
        $now = $this->clock->now();

        $daysRemaining = (int) floor(($expiresAt->getTimestamp() - $now->getTimestamp()) / 86400);

        $status = match (true) {
            $daysRemaining < 0 => ClientSecretExpiryStatus::Expired,
            $daysRemaining <= $this->warningDays => ClientSecretExpiryStatus::ExpiringSoon,
            default => ClientSecretExpiryStatus::Ok,
        };

        return new ClientSecretExpiry($providerKey, $status, $expiresAt, $daysRemaining);
    }

    /**
     * @return array<string, ClientSecretExpiry>
     */
    public function getAllStatuses(): array
    {
        $statuses = [];
        foreach ($this->getProviderKeys() as $providerKey) {
            $statuses[$providerKey] = $this->getStatus($providerKey);
        }

        return $statuses;
    }
}
