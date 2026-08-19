<?php

namespace ItkDev\OpenIdConnectBundle\Log;

use Psr\Log\LoggerInterface;

/**
 * Writes this bundle's authentication audit trail.
 *
 * An audit log answers "who did what, when?", which makes it a different product
 * from the failure logging elsewhere in this bundle even though both use a PSR-3
 * logger: it records successes as well as failures, it is written at `info`
 * because a successful login is not an error, and it is expected to be retained
 * on its own schedule. Keeping every record shaped here — fixed event names and a
 * fixed context schema — is what makes the trail queryable rather than a pile of
 * prose. See the "Audit logging" section of the README.
 *
 * Audit records identify people, so the trail is **opt-in**: with
 * `audit_options.enabled` false every method returns before a record is built, so
 * no personal data is assembled at all.
 */
class AuthenticationAuditLogger
{
    public const string EVENT_LOGIN_SUCCEEDED = 'authentication.login_succeeded';
    public const string EVENT_LOGIN_FAILED = 'authentication.login_failed';
    public const string EVENT_CLI_TOKEN_ISSUED = 'authentication.cli_token_issued';
    public const string EVENT_CLI_TOKEN_REISSUED = 'authentication.cli_token_reissued';
    public const string EVENT_CLI_TOKEN_DENIED = 'authentication.cli_token_denied';

    public const string METHOD_OIDC = 'oidc';
    public const string METHOD_CLI_TOKEN = 'cli_token';

    public const string IDENTIFIER_RAW = 'raw';
    public const string IDENTIFIER_HASHED = 'hashed';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = false,
        private readonly string $identifierMode = self::IDENTIFIER_RAW,
        private readonly string $identifierSecret = '',
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * A login that established a session.
     */
    public function loginSucceeded(string $method, string $subject, ?string $provider, ?string $firewall, ?string $ip): void
    {
        $this->record(self::EVENT_LOGIN_SUCCEEDED, [
            'method' => $method,
            'subject' => $this->subject($subject),
            'provider' => $provider,
            'firewall' => $firewall,
            'ip' => $ip,
            'outcome' => 'success',
            'reason' => null,
        ]);
    }

    /**
     * A rejected login attempt.
     *
     * `subject` is null on purpose for the OIDC flow: this bundle raises its
     * failures inside `authenticate()`, before a passport exists, so the security
     * events carry no identity to record. Inventing one would be worse than
     * leaving it absent.
     */
    public function loginFailed(string $method, ?string $subject, ?string $provider, ?string $firewall, ?string $ip, string $reason): void
    {
        $this->record(self::EVENT_LOGIN_FAILED, [
            'method' => $method,
            'subject' => null === $subject ? null : $this->subject($subject),
            'provider' => $provider,
            'firewall' => $firewall,
            'ip' => $ip,
            'outcome' => 'failure',
            'reason' => $reason,
        ]);
    }

    /**
     * A CLI login token was minted, or an existing one handed out again.
     *
     * The token itself is never recorded: it is bearer-equivalent, so an audit
     * trail containing it would be a credential store.
     */
    public function cliTokenIssued(string $subject, bool $reissued): void
    {
        $this->record($reissued ? self::EVENT_CLI_TOKEN_REISSUED : self::EVENT_CLI_TOKEN_ISSUED, [
            'method' => self::METHOD_CLI_TOKEN,
            'subject' => $this->subject($subject),
            'provider' => null,
            'firewall' => null,
            'ip' => null,
            'outcome' => 'success',
            'reason' => null,
        ]);
    }

    /**
     * A CLI login token was requested for an identifier that does not exist.
     */
    public function cliTokenDenied(string $subject, string $reason): void
    {
        $this->record(self::EVENT_CLI_TOKEN_DENIED, [
            'method' => self::METHOD_CLI_TOKEN,
            'subject' => $this->subject($subject),
            'provider' => null,
            'firewall' => null,
            'ip' => null,
            'outcome' => 'failure',
            'reason' => $reason,
        ]);
    }

    /**
     * @param array<string, string|null> $context
     */
    private function record(string $event, array $context): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->logger->info($event, ['event' => $event] + $context);
    }

    /**
     * Pseudonymise the identifier when asked to.
     *
     * Keyed with the application secret rather than a plain digest: identifiers
     * here are email addresses, and a bare SHA-256 of an email is recovered from a
     * wordlist in seconds, which would make "hashed" a false promise. The key is
     * stable for a deployment, so records still correlate.
     */
    private function subject(string $identifier): string
    {
        if (self::IDENTIFIER_RAW === $this->identifierMode) {
            return $identifier;
        }

        return hash_hmac('sha256', $identifier, $this->identifierSecret);
    }
}
