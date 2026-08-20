<?php

namespace ItkDev\OpenIdConnectBundle\Util;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

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
        private readonly LoggerInterface $logger,
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

        // An empty value means "now" to DateTimeImmutable, which would quietly
        // report a secret as expiring today. An environment variable that is set
        // but blank arrives here, so it is caught before parsing.
        if ('' === trim($configured)) {
            $this->reportUnusableDate($providerKey, $configured);

            return new ClientSecretExpiry($providerKey, ClientSecretExpiryStatus::Unknown);
        }

        // Anchored to midnight UTC: a date-only value like "2027-01-31" otherwise
        // inherits the current time of day, which would make daysRemaining drift
        // depending on when the check ran.
        try {
            $expiresAt = new \DateTimeImmutable($configured, new \DateTimeZone('UTC'));
        } catch (\Exception $exception) {
            $this->reportUnusableDate($providerKey, $configured, $exception);

            return new ClientSecretExpiry($providerKey, ClientSecretExpiryStatus::Unknown);
        }

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
     * Report a date that cannot be used.
     *
     * A literal is rejected when the container compiles, but the value comes from
     * the environment in every real deployment and is still an unresolved
     * placeholder then, so what it resolves to can only be judged here. Validation
     * closures and environment variables do coexist on that node; what Symfony
     * refuses is a validated node that also disallows empty values, which is why
     * `cannotBeEmpty()` is absent from it.
     * So it is reported rather than thrown — a mistyped date must not take an
     * application down — but reported loudly, because the effect is that nothing is
     * monitoring this secret, and silence would equal not having the feature.
     */
    private function reportUnusableDate(string $providerKey, string $configured, ?\Exception $exception = null): void
    {
        $this->logger->error('OIDC client secret expiry date could not be parsed; this provider is not being monitored', array_filter([
            'provider' => $providerKey,
            'configured' => $configured,
            'exception' => $exception,
        ], static fn (mixed $value): bool => null !== $value));
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
