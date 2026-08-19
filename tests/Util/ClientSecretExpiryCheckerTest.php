<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Util;

use ItkDev\OpenIdConnectBundle\Util\ClientSecretExpiryChecker;
use ItkDev\OpenIdConnectBundle\Util\ClientSecretExpiryStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

class ClientSecretExpiryCheckerTest extends TestCase
{
    private const string NOW = '2026-08-19 09:00:00';

    /**
     * @param array<string, string|null> $expiryDates
     */
    private function createChecker(array $expiryDates, int $warningDays = 30): ClientSecretExpiryChecker
    {
        return new ClientSecretExpiryChecker(
            new MockClock(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'))),
            $expiryDates,
            $warningDays,
        );
    }

    public function testUnknownWhenNoDateConfigured(): void
    {
        // Distinct from Ok on purpose: every installation is here until it sets a
        // date, and treating that as "fine" would defeat the point.
        $status = $this->createChecker(['azure' => null])->getStatus('azure');

        $this->assertSame(ClientSecretExpiryStatus::Unknown, $status->status);
        $this->assertNull($status->expiresAt);
        $this->assertNull($status->daysRemaining);
        $this->assertFalse($status->isExpired());
        $this->assertFalse($status->isExpiringSoon());
    }

    public function testUnknownForAProviderWithNoEntryAtAll(): void
    {
        $this->assertSame(
            ClientSecretExpiryStatus::Unknown,
            $this->createChecker([])->getStatus('never-heard-of-it')->status,
        );
    }

    /**
     * Both sides of every comparison, since an off-by-one here is the difference
     * between warning in time and not warning at all.
     *
     * @return iterable<string, array{string, int, ClientSecretExpiryStatus, int}>
     */
    public static function boundaryProvider(): iterable
    {
        //                                      date,            warningDays, expected status,                        expected daysRemaining
        yield 'long in the future' => ['2027-08-19 09:00:00', 30, ClientSecretExpiryStatus::Ok, 365];
        yield 'one day outside the window' => ['2026-09-19 09:00:00', 30, ClientSecretExpiryStatus::Ok, 31];
        yield 'exactly on the window edge' => ['2026-09-18 09:00:00', 30, ClientSecretExpiryStatus::ExpiringSoon, 30];
        yield 'one day inside the window' => ['2026-09-17 09:00:00', 30, ClientSecretExpiryStatus::ExpiringSoon, 29];
        yield 'tomorrow' => ['2026-08-20 09:00:00', 30, ClientSecretExpiryStatus::ExpiringSoon, 1];
        yield 'exactly now' => [self::NOW, 30, ClientSecretExpiryStatus::ExpiringSoon, 0];
        yield 'just past' => ['2026-08-19 08:59:59', 30, ClientSecretExpiryStatus::Expired, -1];
        yield 'long past' => ['2026-07-19 09:00:00', 30, ClientSecretExpiryStatus::Expired, -31];
        yield 'zero threshold, future' => ['2026-08-20 09:00:00', 0, ClientSecretExpiryStatus::Ok, 1];
        yield 'zero threshold, today' => [self::NOW, 0, ClientSecretExpiryStatus::ExpiringSoon, 0];
    }

    #[DataProvider('boundaryProvider')]
    public function testStatusBoundaries(string $date, int $warningDays, ClientSecretExpiryStatus $expected, int $expectedDaysRemaining): void
    {
        $status = $this->createChecker(['azure' => $date], $warningDays)->getStatus('azure');

        $this->assertSame($expected, $status->status);
        $this->assertSame($expectedDaysRemaining, $status->daysRemaining);
    }

    public function testDateOnlyValuesAreAnchoredToUtcMidnight(): void
    {
        // A bare "2026-09-18" must not pick up the current time of day, or
        // daysRemaining would drift depending on when the check happened to run.
        $status = $this->createChecker(['azure' => '2026-09-18'])->getStatus('azure');

        $this->assertNotNull($status->expiresAt);
        $this->assertSame('2026-09-18 00:00:00', $status->expiresAt->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $status->expiresAt->getTimezone()->getName());
    }

    public function testExpiredAndExpiringSoonHelpers(): void
    {
        $expired = $this->createChecker(['azure' => '2026-01-01'])->getStatus('azure');
        $this->assertTrue($expired->isExpired());
        $this->assertFalse($expired->isExpiringSoon());

        $soon = $this->createChecker(['azure' => '2026-09-01'])->getStatus('azure');
        $this->assertFalse($soon->isExpired());
        $this->assertTrue($soon->isExpiringSoon());
    }

    public function testProviderKeysAndAllStatuses(): void
    {
        $checker = $this->createChecker([
            'azure' => '2027-01-31',
            'legacy' => '2026-01-01',
            'undated' => null,
        ]);

        $this->assertSame(['azure', 'legacy', 'undated'], $checker->getProviderKeys());

        $statuses = $checker->getAllStatuses();
        $this->assertSame(['azure', 'legacy', 'undated'], array_keys($statuses));
        $this->assertSame(ClientSecretExpiryStatus::Ok, $statuses['azure']->status);
        $this->assertSame(ClientSecretExpiryStatus::Expired, $statuses['legacy']->status);
        $this->assertSame(ClientSecretExpiryStatus::Unknown, $statuses['undated']->status);
        $this->assertSame('legacy', $statuses['legacy']->providerKey);
    }

    public function testToArrayShape(): void
    {
        $status = $this->createChecker(['azure' => '2026-09-01'])->getStatus('azure');

        // Shared by the warning log context and the health endpoint, so the keys
        // are part of the contract.
        $this->assertSame([
            'provider' => 'azure',
            'status' => 'expiring_soon',
            'expires_at' => '2026-09-01T00:00:00+00:00',
            'days_remaining' => 12,
        ], $status->toArray());
    }

    public function testToArrayShapeWhenUnknown(): void
    {
        $this->assertSame([
            'provider' => 'azure',
            'status' => 'unknown',
            'expires_at' => null,
            'days_remaining' => null,
        ], $this->createChecker(['azure' => null])->getStatus('azure')->toArray());
    }
}
