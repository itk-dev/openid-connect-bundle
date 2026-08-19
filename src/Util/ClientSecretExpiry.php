<?php

namespace ItkDev\OpenIdConnectBundle\Util;

/**
 * What is known about one provider's client secret expiry.
 */
readonly class ClientSecretExpiry
{
    /**
     * @param \DateTimeImmutable|null $expiresAt     null when no expiry date is configured
     * @param int|null                $daysRemaining negative once expired, null when unknown
     */
    public function __construct(
        public string $providerKey,
        public ClientSecretExpiryStatus $status,
        public ?\DateTimeImmutable $expiresAt = null,
        public ?int $daysRemaining = null,
    ) {
    }

    public function isExpired(): bool
    {
        return ClientSecretExpiryStatus::Expired === $this->status;
    }

    public function isExpiringSoon(): bool
    {
        return ClientSecretExpiryStatus::ExpiringSoon === $this->status;
    }

    /**
     * The expiry date for a human-readable message.
     *
     * `Expired` always carries a date when the status came from
     * `ClientSecretExpiryChecker`, but this class does not enforce that pairing,
     * so the absent case is handled rather than assumed.
     */
    public function expiresAtForHumans(): string
    {
        return $this->expiresAt?->format('Y-m-d') ?? 'an unknown date';
    }

    /**
     * Shape used by both the log context and the health endpoint.
     *
     * @return array{provider: string, status: string, expires_at: string|null, days_remaining: int|null}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->providerKey,
            'status' => $this->status->value,
            'expires_at' => $this->expiresAt?->format(\DateTimeInterface::ATOM),
            'days_remaining' => $this->daysRemaining,
        ];
    }
}
