<?php

namespace ItkDev\OpenIdConnectBundle\Tests\DependencyInjection;

use ItkDev\OpenIdConnectBundle\DependencyInjection\Compiler\ConfiguredLoggerPass;
use ItkDev\OpenIdConnectBundle\Security\OpenIdLoginAuthenticator;
use ItkDev\OpenIdConnectBundle\Tests\ItkDevOpenIdConnectBundleTestingKernel;
use ItkDev\OpenIdConnectBundle\Tests\RestoresExceptionHandlers;
use ItkDev\OpenIdConnectBundle\Tests\Security\ConsumerAuthenticator;
use ItkDev\OpenIdConnectBundle\Tests\TestLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * `logging_options.logger` asserted against the compiled container.
 *
 * The extension test asserts that the instanceof conditional carries exactly one
 * `setLogger` call, which is true and says nothing about the service that gets
 * built: FrameworkBundle appends a second call for every `LoggerAwareInterface`
 * service, and the last one is the one that runs. Only the built service shows
 * which logger the authenticator ends up holding.
 *
 * The testing kernel registers this bundle before FrameworkBundle, which is the
 * order in which the bundle's call loses. Under the conventional order these tests
 * pass without the pass at all.
 */
class ConfiguredLoggerPassTest extends TestCase
{
    use RestoresExceptionHandlers;

    private ItkDevOpenIdConnectBundleTestingKernel $kernel;

    protected function setUp(): void
    {
        $this->captureExceptionHandler();
        $this->kernel = $this->boot('itkdev_openid_connect_configured_logger.yml');
    }

    protected function tearDown(): void
    {
        $this->restoreExceptionHandlers();
    }

    private function boot(string $bundleConfig): ItkDevOpenIdConnectBundleTestingKernel
    {
        $kernel = new ItkDevOpenIdConnectBundleTestingKernel([
            __DIR__.'/../config/framework.yml',
            __DIR__.'/../config/framework_routing.yml',
            __DIR__.'/../config/security_consumer.yml',
            __DIR__.'/../config/'.$bundleConfig,
        ]);
        $kernel->boot();

        return $kernel;
    }

    private function configuredLogger(): TestLogger
    {
        $logger = $this->kernel->getContainer()->get(TestLogger::class);
        $this->assertInstanceOf(TestLogger::class, $logger);

        return $logger;
    }

    public function testTheBuiltAuthenticatorHoldsTheConfiguredLogger(): void
    {
        $authenticator = $this->kernel->getContainer()->get(ConsumerAuthenticator::class);
        $this->assertInstanceOf(ConsumerAuthenticator::class, $authenticator);

        $logger = (new \ReflectionProperty(OpenIdLoginAuthenticator::class, 'logger'))->getValue($authenticator);

        $this->assertSame(
            $this->configuredLogger(),
            $logger,
            'The application logger overwrote the configured one, so logging_options.logger turns on bundle registration order.'
        );
    }

    /**
     * The same thing again through behaviour, because holding the right object and
     * writing to it are not quite the same claim.
     */
    public function testAFailedLoginIsWrittenToTheConfiguredLogger(): void
    {
        $request = Request::create('/protected?state=does-not-match&code=some-code');
        $session = new Session(new MockArraySessionStorage());
        $session->set('oauth2provider', 'test_provider_1');
        $session->set('oauth2state', 'the-real-state');
        $session->set('oauth2nonce', 'the-real-nonce');
        $request->setSession($session);

        $this->kernel->handle($request, catch: true);

        $this->assertContains(
            'OIDC login failed: invalid state',
            array_column($this->configuredLogger()->records, 'message'),
        );
    }

    /**
     * An alias is a service id like any other as far as configuration goes, but by
     * the time this pass runs the references it is compared against have already been
     * rewritten to the concrete id — so matching on the alias would quietly fail and
     * leave exactly the ordering dependency this pass exists to remove.
     */
    public function testALoggerConfiguredByAliasIsResolved(): void
    {
        $this->kernel = $this->boot('itkdev_openid_connect_alias_logger.yml');

        $authenticator = $this->kernel->getContainer()->get(ConsumerAuthenticator::class);
        $this->assertInstanceOf(ConsumerAuthenticator::class, $authenticator);

        $logger = (new \ReflectionProperty(OpenIdLoginAuthenticator::class, 'logger'))->getValue($authenticator);

        $this->assertSame($this->configuredLogger(), $logger);
    }

    /**
     * A service that opts out of autoconfiguration keeps its NullLogger: the pass
     * must not decide to start logging on a consumer's behalf.
     */
    public function testAServiceWithoutAutoconfigurationIsLeftAlone(): void
    {
        $authenticator = $this->kernel->getContainer()->get(ConsumerAuthenticator::class.'.not_autoconfigured');
        $this->assertInstanceOf(ConsumerAuthenticator::class, $authenticator);

        $logger = (new \ReflectionProperty(OpenIdLoginAuthenticator::class, 'logger'))->getValue($authenticator);

        $this->assertInstanceOf(LoggerInterface::class, $logger);
        $this->assertNotSame($this->configuredLogger(), $logger);
    }

    public function testTheParameterDoesNotLingerInTheContainer(): void
    {
        $this->assertFalse(
            $this->kernel->getContainer()->hasParameter(ConfiguredLoggerPass::LOGGER_PARAMETER),
            'The pass consumes its parameter rather than leaving it in a consumer container'
        );
    }

    /**
     * @param array<int, array{0: string, 1: array<mixed>}> $calls
     */
    private function process(array $calls, string|array|null $parameter = 'configured.logger'): Definition
    {
        $container = new ContainerBuilder();
        if (null !== $parameter) {
            $container->setParameter(ConfiguredLoggerPass::LOGGER_PARAMETER, $parameter);
        }
        $definition = $container->register('some.service', \stdClass::class);
        $definition->setMethodCalls($calls);

        (new ConfiguredLoggerPass())->process($container);

        return $definition;
    }

    public function testAParameterThatIsNotAServiceIdIsIgnored(): void
    {
        $calls = [['setLogger', [new Reference('configured.logger')]]];

        $this->assertEquals($calls, $this->process($calls, parameter: ['not', 'a', 'service id'])->getMethodCalls());
    }

    public function testNoParameterMeansNoConfiguredLogger(): void
    {
        $calls = [['setLogger', [new Reference('logger')]]];

        $this->assertEquals($calls, $this->process($calls, parameter: null)->getMethodCalls());
    }

    public function testAnUnrelatedLoggerAwareServiceKeepsItsCall(): void
    {
        // Every LoggerAware service in the application carries this call. Only the
        // ones the bundle also wrote to are ours to rewrite.
        $calls = [['setLogger', [new Reference('logger')]]];

        $this->assertEquals($calls, $this->process($calls)->getMethodCalls());
    }

    public function testTheConfiguredCallIsMovedLastAndTheOtherOneDropped(): void
    {
        $definition = $this->process([
            ['setLogger', [new Reference('configured.logger')]],
            ['setDependency', []],
            ['setLogger', [new Reference('logger')]],
        ]);

        $this->assertEquals([
            ['setDependency', []],
            ['setLogger', [new Reference('configured.logger')]],
        ], $definition->getMethodCalls());
    }
}
