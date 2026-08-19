<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Command;

use ItkDev\OpenIdConnectBundle\Command\UserLoginCommand;
use ItkDev\OpenIdConnectBundle\Log\AuthenticationAuditLogger;
use ItkDev\OpenIdConnectBundle\Tests\TestLogger;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class UserLoginCommandTest extends TestCase
{
    /** @var CliLoginHelper&Stub */
    private CliLoginHelper $stubCliLoginHelper;
    /** @var UrlGeneratorInterface&Stub */
    private UrlGeneratorInterface $stubUrlGenerator;
    /** @var UserProviderInterface&Stub */
    private UserProviderInterface $stubUserProvider;
    private UserLoginCommand $command;
    private TestLogger $auditLog;

    protected function setUp(): void
    {
        $this->auditLog = new TestLogger();
        $this->stubCliLoginHelper = $this->createStub(CliLoginHelper::class);
        $this->stubUrlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $this->stubUserProvider = $this->createStub(UserProviderInterface::class);

        $this->command = new UserLoginCommand(
            $this->stubCliLoginHelper,
            'cli_login_route',
            $this->stubUrlGenerator,
            $this->stubUserProvider,
            new AuthenticationAuditLogger($this->auditLog, enabled: true)
        );
    }

    public function testExecuteSuccess(): void
    {
        $this->stubCliLoginHelper
            ->method('createToken')
            ->willReturn('generated-token');

        $this->stubUrlGenerator
            ->method('generate')
            ->willReturn('https://app.example.org/login?loginToken=generated-token');

        $tester = new CommandTester($this->command);
        $result = $tester->execute(['username' => 'testuser']);

        $this->assertSame(Command::SUCCESS, $result);
        $this->assertStringContainsString('https://app.example.org/login?loginToken=generated-token', $tester->getDisplay());
    }

    public function testExecutePassesTokenAndRouteToUrlGenerator(): void
    {
        $this->stubCliLoginHelper
            ->method('createToken')
            ->willReturn('generated-token');

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('cli_login_route', ['loginToken' => 'generated-token'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://app.example.org/login?loginToken=generated-token');

        $command = new UserLoginCommand(
            $this->stubCliLoginHelper,
            'cli_login_route',
            $urlGenerator,
            $this->stubUserProvider,
            new AuthenticationAuditLogger($this->auditLog, enabled: true)
        );

        $tester = new CommandTester($command);
        $this->assertSame(Command::SUCCESS, $tester->execute(['username' => 'testuser']));
    }

    public function testExecuteUserNotFound(): void
    {
        $this->stubUserProvider
            ->method('loadUserByIdentifier')
            ->willThrowException(new UserNotFoundException());

        $tester = new CommandTester($this->command);
        $result = $tester->execute(['username' => 'nonexistent']);

        $this->assertSame(Command::FAILURE, $result);
        $this->assertStringContainsString('User does not exist', $tester->getDisplay());

        // Requesting a login token for an identifier that does not exist is
        // enumeration-relevant, and went unrecorded before the audit trail.
        $record = $this->auditLog->singleRecord();
        $this->assertSame(AuthenticationAuditLogger::EVENT_CLI_TOKEN_DENIED, $record['message']);
        $this->assertSame('nonexistent', $record['context']['subject']);
        $this->assertSame('failure', $record['context']['outcome']);
    }

    public function testAuditNeverRecordsTheTokenOrTheLoginUrl(): void
    {
        $this->stubCliLoginHelper->method('createToken')->willReturn('generated-token');
        $this->stubUrlGenerator->method('generate')->willReturn('https://app.example.org/login?loginToken=generated-token');

        $tester = new CommandTester($this->command);
        $tester->execute(['username' => 'test@example.org']);

        // The command itself records nothing on success — issuance is audited in
        // CliLoginHelper, which is stubbed here. What matters is that neither the
        // token nor the URL embedding it can reach a record.
        $serialised = json_encode($this->auditLog->records, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('generated-token', $serialised);
        $this->assertStringNotContainsString('loginToken', $serialised);
    }
}
