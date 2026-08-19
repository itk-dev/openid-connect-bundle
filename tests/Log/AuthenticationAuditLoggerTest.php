<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Log;

use ItkDev\OpenIdConnectBundle\Log\AuthenticationAuditLogger;
use ItkDev\OpenIdConnectBundle\Tests\TestLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

class AuthenticationAuditLoggerTest extends TestCase
{
    private TestLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new TestLogger();
    }

    private function createLogger(bool $enabled = true, string $identifier = AuthenticationAuditLogger::IDENTIFIER_RAW, string $secret = 'test-secret'): AuthenticationAuditLogger
    {
        return new AuthenticationAuditLogger($this->logger, $enabled, $identifier, $secret);
    }

    public function testDisabledByDefault(): void
    {
        // The default matters: an installation that upgrades without opting in
        // must not start writing personal data.
        $auditLogger = new AuthenticationAuditLogger($this->logger);

        $this->assertFalse($auditLogger->isEnabled());
    }

    /**
     * @return iterable<string, array{callable(AuthenticationAuditLogger): void}>
     */
    public static function everyRecordProvider(): iterable
    {
        yield 'login succeeded' => [fn (AuthenticationAuditLogger $l) => $l->loginSucceeded('oidc', 'App\\Security\\AppAuthenticator', 'user@example.org', 'azure', 'main', '203.0.113.4')];
        yield 'login failed' => [fn (AuthenticationAuditLogger $l) => $l->loginFailed('oidc', 'App\\Security\\AppAuthenticator', null, 'azure', 'main', '203.0.113.4', 'Invalid state')];
        yield 'cli token issued' => [fn (AuthenticationAuditLogger $l) => $l->cliTokenIssued('user@example.org', reissued: false)];
        yield 'cli token reissued' => [fn (AuthenticationAuditLogger $l) => $l->cliTokenIssued('user@example.org', reissued: true)];
        yield 'cli token denied' => [fn (AuthenticationAuditLogger $l) => $l->cliTokenDenied('nobody@example.org', 'User does not exist')];
    }

    /**
     * Every entry point must be silent when the trail is off — not merely
     * unwritten, since a record built and discarded still assembled the data.
     *
     * @param callable(AuthenticationAuditLogger): void $emit
     */
    #[DataProvider('everyRecordProvider')]
    public function testNothingIsRecordedWhenDisabled(callable $emit): void
    {
        $emit($this->createLogger(enabled: false));

        $this->assertSame([], $this->logger->records);
    }

    /**
     * @param callable(AuthenticationAuditLogger): void $emit
     */
    #[DataProvider('everyRecordProvider')]
    public function testEveryRecordUsesInfoAndTheFixedSchema(callable $emit): void
    {
        $emit($this->createLogger());

        $record = $this->logger->singleRecord();
        $this->assertSame(LogLevel::INFO, $record['level'], 'An audit record is not an error, so it must not compete with operational thresholds');

        // The schema is the contract that makes the trail queryable; every record
        // carries every key, present or null.
        $this->assertSame(
            ['event', 'method', 'authenticator', 'subject', 'provider', 'firewall', 'ip', 'outcome', 'reason'],
            array_keys($record['context']),
        );
        $this->assertSame($record['message'], $record['context']['event'] ?? null);
        $this->assertContains($record['context']['outcome'] ?? null, ['success', 'failure']);
    }

    public function testLoginSucceededRecord(): void
    {
        $this->createLogger()->loginSucceeded(
            AuthenticationAuditLogger::METHOD_OIDC,
            'App\\Security\\AppAuthenticator',
            'user@example.org',
            'azure',
            'main',
            '203.0.113.4',
        );

        $record = $this->logger->singleRecord();
        $this->assertSame(AuthenticationAuditLogger::EVENT_LOGIN_SUCCEEDED, $record['message']);
        $this->assertSame('oidc', $record['context']['method']);
        $this->assertSame('App\\Security\\AppAuthenticator', $record['context']['authenticator']);
        $this->assertSame('user@example.org', $record['context']['subject']);
        $this->assertSame('azure', $record['context']['provider']);
        $this->assertSame('main', $record['context']['firewall']);
        $this->assertSame('203.0.113.4', $record['context']['ip']);
        $this->assertSame('success', $record['context']['outcome']);
        $this->assertNull($record['context']['reason']);
    }

    public function testLoginFailedRecordKeepsReasonAndOmitsSubject(): void
    {
        $this->createLogger()->loginFailed(
            AuthenticationAuditLogger::METHOD_OIDC,
            'App\\Security\\AppAuthenticator',
            null,
            'azure',
            'main',
            '203.0.113.4',
            'invalid_client',
        );

        $record = $this->logger->singleRecord();
        $this->assertSame(AuthenticationAuditLogger::EVENT_LOGIN_FAILED, $record['message']);
        $this->assertSame('failure', $record['context']['outcome']);
        $this->assertSame('invalid_client', $record['context']['reason']);
        $this->assertNull($record['context']['subject'], 'This bundle fails before a passport exists, so there is no identity to record');
    }

    public function testLoginFailedHashesTheSubjectWhenOneIsAvailable(): void
    {
        $this->createLogger(identifier: AuthenticationAuditLogger::IDENTIFIER_HASHED)
            ->loginFailed('oidc', 'App\\Security\\AppAuthenticator', 'user@example.org', null, 'main', null, 'nope');

        $subject = $this->logger->singleRecord()['context']['subject'];
        $this->assertNotSame('user@example.org', $subject);
        $this->assertSame(hash_hmac('sha256', 'user@example.org', 'test-secret'), $subject);
    }

    public function testCliTokenIssuedAndReissuedAreDistinctEvents(): void
    {
        $auditLogger = $this->createLogger();
        $auditLogger->cliTokenIssued('user@example.org', reissued: false);
        $auditLogger->cliTokenIssued('user@example.org', reissued: true);

        $this->assertSame(AuthenticationAuditLogger::EVENT_CLI_TOKEN_ISSUED, $this->logger->records[0]['message']);
        $this->assertSame(AuthenticationAuditLogger::EVENT_CLI_TOKEN_REISSUED, $this->logger->records[1]['message']);
    }

    public function testCliTokenDeniedRecord(): void
    {
        $this->createLogger()->cliTokenDenied('nobody@example.org', 'User does not exist');

        $record = $this->logger->singleRecord();
        $this->assertSame(AuthenticationAuditLogger::EVENT_CLI_TOKEN_DENIED, $record['message']);
        $this->assertNull($record['context']['authenticator'], 'A console command has no authenticator');
        $this->assertSame('nobody@example.org', $record['context']['subject']);
        $this->assertSame('failure', $record['context']['outcome']);
        $this->assertSame('User does not exist', $record['context']['reason']);
    }

    public function testHashedIdentifiersAreStableAndKeyed(): void
    {
        $this->createLogger(identifier: AuthenticationAuditLogger::IDENTIFIER_HASHED)
            ->cliTokenIssued('user@example.org', reissued: false);
        $first = $this->logger->singleRecord()['context']['subject'];

        // Stable, so records for the same person still correlate.
        $this->logger->records = [];
        $this->createLogger(identifier: AuthenticationAuditLogger::IDENTIFIER_HASHED)
            ->cliTokenIssued('user@example.org', reissued: false);
        $this->assertSame($first, $this->logger->singleRecord()['context']['subject']);

        // Keyed, so a wordlist of email addresses does not recover it. A bare
        // digest would make "hashed" a false promise.
        $this->logger->records = [];
        $this->createLogger(identifier: AuthenticationAuditLogger::IDENTIFIER_HASHED, secret: 'other-secret')
            ->cliTokenIssued('user@example.org', reissued: false);
        $this->assertNotSame($first, $this->logger->singleRecord()['context']['subject']);
        $this->assertNotSame(hash('sha256', 'user@example.org'), $first);
    }

    public function testAnUnrecognisedIdentifierModeFailsSafeByHashing(): void
    {
        // Reachable from a blank or mistyped environment variable. Writing the
        // identifier in the clear would be the wrong way to fail.
        $this->createLogger(identifier: '')->cliTokenIssued('user@example.org', reissued: false);

        $subject = $this->logger->singleRecord()['context']['subject'];
        $this->assertNotSame('user@example.org', $subject);
        $this->assertSame(hash_hmac('sha256', 'user@example.org', 'test-secret'), $subject);
    }

    public function testRawIsTheDefaultIdentifierMode(): void
    {
        $this->createLogger()->cliTokenIssued('user@example.org', reissued: false);

        $this->assertSame('user@example.org', $this->logger->singleRecord()['context']['subject']);
    }
}
