<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Command;

use ItkDev\OpenIdConnectBundle\Command\UserLoginCommand;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class UserLoginCommandTest extends TestCase
{
    private CliLoginHelper $stubCliLoginHelper;
    private UrlGeneratorInterface $stubUrlGenerator;
    private UserProviderInterface $stubUserProvider;
    private UserLoginCommand $command;

    protected function setUp(): void
    {
        $this->stubCliLoginHelper = $this->createStub(CliLoginHelper::class);
        $this->stubUrlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $this->stubUserProvider = $this->createStub(UserProviderInterface::class);

        $this->command = new UserLoginCommand(
            $this->stubCliLoginHelper,
            'cli_login_route',
            $this->stubUrlGenerator,
            $this->stubUserProvider
        );
    }

    public function testExecuteSuccess(): void
    {
        $this->stubCliLoginHelper
            ->method('createToken')
            ->willReturn('generated-token');

        $this->stubUrlGenerator
            ->method('generate')
            ->willReturn('https://app.com/login?loginToken=generated-token');

        $tester = new CommandTester($this->command);
        $result = $tester->execute(['username' => 'testuser']);

        $this->assertSame(Command::SUCCESS, $result);
        $this->assertStringContainsString('https://app.com/login?loginToken=generated-token', $tester->getDisplay());
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
    }
}
