<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Util;

use ItkDev\OpenIdConnectBundle\Tests\TestLogger;
use ItkDev\OpenIdConnectBundle\Util\ClientSecretExpiryChecker;
use ItkDev\OpenIdConnectBundle\Util\ClientSecretExpiryStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\MockClock;

class ClientSecretExpiryCheckerTest extends TestCase
{
    private const string NOW = '2026-08-19 09:00:00';

    private TestLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new TestLogger();
    }

    /**
     * @param array<string, string|null> $expiryDates
     */
    private function createChecker(array $expiryDates, int $warningDays = 30): ClientSecretExpiryChecker
    {
        return new ClientSecretExpiryChecker(
            new MockClock(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'))),
            $expiryDates,
            $warningDays,
            $this->logger,
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

    /**
     * Values that arrive from an environment variable that is set but says nothing.
     *
     * @return iterable<string, array{string}>
     */
    public static function blankDateProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
    }

    #[DataProvider('blankDateProvider')]
    public function testBlankDateIsReportedAndTreatedAsUnknown(string $configured): void
    {
        // Without the guard these reach DateTimeImmutable, which reads them as
        // "now" and would quietly report the secret as expiring today.
        $status = $this->createChecker(['azure' => $configured])->getStatus('azure');

        $this->assertSame(ClientSecretExpiryStatus::Unknown, $status->status);
        $this->assertNull($status->expiresAt);

        $record = $this->logger->singleRecord();
        $this->assertSame(LogLevel::ERROR, $record['level']);
        $this->assertStringContainsString('could not be parsed', $record['message']);
        $this->assertSame($configured, $record['context']['configured']);
        $this->assertArrayNotHasKey('exception', $record['context'], 'Nothing threw, so there is no exception to attach');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedDateProvider(): iterable
    {
        // Cannot be rejected when the container compiles: the value is an
        // environment variable in every real deployment, and Symfony refuses one on
        // a validated node.
        yield 'prose' => ['whenever'];
        yield 'unresolved placeholder' => ['env_1a2b3c_AZURE_AZ_OIDC_CLIENT_SECRET_EXPIRES_AT'];
    }

    #[DataProvider('malformedDateProvider')]
    public function testMalformedDateIsReportedAndTreatedAsUnknown(string $configured): void
    {
        // Unknown rather than fatal: a mistyped date must not take an application
        // down. But it is logged, because the effect is that nothing is monitoring
        // this secret — silence would be the same as no feature at all.
        $status = $this->createChecker(['azure' => $configured])->getStatus('azure');

        $this->assertSame(ClientSecretExpiryStatus::Unknown, $status->status);
        $this->assertNull($status->daysRemaining);

        $record = $this->logger->singleRecord();
        $this->assertSame(LogLevel::ERROR, $record['level']);
        $this->assertSame('azure', $record['context']['provider']);
        $this->assertSame($configured, $record['context']['configured']);
        $this->assertInstanceOf(\Exception::class, $record['context']['exception'] ?? null, 'The parse failure is attached for debugging');
    }

    public function testAParseableDateLogsNothing(): void
    {
        $this->createChecker(['azure' => '2027-01-31'])->getStatus('azure');

        $this->assertSame([], $this->logger->records);
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
