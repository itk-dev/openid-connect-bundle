<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Util;

use ItkDev\OpenIdConnectBundle\Exception\CacheException;
use ItkDev\OpenIdConnectBundle\Exception\OpenIdConnectBundleExceptionInterface;
use ItkDev\OpenIdConnectBundle\Exception\TokenNotFoundException;
use ItkDev\OpenIdConnectBundle\Log\AuthenticationAuditLogger;
use ItkDev\OpenIdConnectBundle\Tests\TestLogger;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\Uuid;

class CliLoginHelperTest extends TestCase
{
    private TestLogger $auditLog;

    protected function setUp(): void
    {
        $this->auditLog = new TestLogger();
    }

    /**
     * The helper under test, with the audit trail switched on so issuance can be
     * asserted. Production defaults to off.
     */
    private function createHelper(CacheItemPoolInterface $cache): CliLoginHelper
    {
        return new CliLoginHelper($cache, new AuthenticationAuditLogger($this->auditLog, enabled: true));
    }

    public function testEncodeAndDecode(): void
    {
        $cache = new ArrayAdapter();
        $cliHelper = $this->createHelper($cache);

        $randomUsername = Uuid::v4()->toBase32();

        $encodedUsername = $cliHelper->encodeKey($randomUsername);
        $decodedUsername = $cliHelper->decodeKey($encodedUsername);

        $this->assertEquals($randomUsername, $decodedUsername);
    }

    public function testDecodeKeyReturnsInputWhenNotValidBase64(): void
    {
        $cache = new ArrayAdapter();
        $cliHelper = $this->createHelper($cache);

        // Strict base64_decode() rejects input with characters outside the
        // base64 alphabet; decodeKey() then returns the value unchanged.
        $notBase64 = 'not valid base64 !!@@';

        $this->assertSame($notBase64, $cliHelper->decodeKey($notBase64));
    }

    public function testThrowExceptionIfTokenDoesNotExist(): void
    {
        // TokenNotFoundException (not just the marker interface) is part of
        // the public contract: CliLoginTokenAuthenticator catches it
        // specifically to distinguish "no such token" from cache failures.
        $this->expectException(TokenNotFoundException::class);

        $cache = new ArrayAdapter();

        $cliHelper = $this->createHelper($cache);

        $cliHelper->getUsername('random_gibberish_token');
    }

    public function testReuseSetTokenRatherThanRemake(): void
    {
        $cache = new ArrayAdapter();

        $cliHelper = $this->createHelper($cache);

        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);
        $token2 = $cliHelper->createToken($testUser);

        $this->assertEquals($token, $token2);

        // Handing out an existing token is a distinct audit outcome from minting
        // one, and neither record may contain the token itself.
        $this->assertSame(AuthenticationAuditLogger::EVENT_CLI_TOKEN_ISSUED, $this->auditLog->records[0]['message']);
        $this->assertSame(AuthenticationAuditLogger::EVENT_CLI_TOKEN_REISSUED, $this->auditLog->records[1]['message']);
        foreach ($this->auditLog->records as $record) {
            $this->assertSame($testUser, $record['context']['subject']);
            $this->assertStringNotContainsString($token, json_encode($record, JSON_THROW_ON_ERROR), 'The token is bearer-equivalent and must never be recorded');
        }
    }

    public function testTokenIsRemovedAfterUse(): void
    {
        $cache = new ArrayAdapter();

        $cliHelper = $this->createHelper($cache);

        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);

        $username = $cliHelper->getUsername($token);
        $this->assertEquals($testUser, $username);

        $this->expectException(OpenIdConnectBundleExceptionInterface::class);

        $cliHelper->getUsername($token);
    }

    public function testBothCacheEntriesAreRemovedAfterUse(): void
    {
        $cache = new ArrayAdapter();

        $cliHelper = $this->createHelper($cache);

        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);

        $this->assertEquals($testUser, $cliHelper->getUsername($token));

        // The reverse entry (username => token) must be gone too; otherwise
        // createToken() would hand out the already-redeemed token again.
        $this->assertFalse($cache->hasItem($cliHelper->encodeKey($testUser)));

        $newToken = $cliHelper->createToken($testUser);
        $this->assertNotSame($token, $newToken);
        $this->assertEquals($testUser, $cliHelper->getUsername($newToken));
    }

    public function testEncodeKeyPrependsNamespace(): void
    {
        $cache = new ArrayAdapter();
        $cliHelper = $this->createHelper($cache);

        // Assert the exact encoding, not just an encode/decode roundtrip:
        // the namespace prefix guards against cache key collisions with the
        // consuming application, and a roundtrip is blind to losing it.
        $this->assertSame(base64_encode('itk-dev-cli-logintest_user'), $cliHelper->encodeKey('test_user'));
    }

    public function testCreateTokenAndGetUsername(): void
    {
        $cache = new ArrayAdapter();

        $cliHelper = $this->createHelper($cache);

        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);

        $username = $cliHelper->getUsername($token);

        $this->assertEquals($testUser, $username);

        // Issuance is audited here; consumption is audited from the security
        // event, so the helper records exactly one thing.
        $record = $this->auditLog->singleRecord();
        $this->assertSame(AuthenticationAuditLogger::EVENT_CLI_TOKEN_ISSUED, $record['message']);
        $this->assertSame($testUser, $record['context']['subject']);
    }

    public function testCreateTokenThrowsCacheExceptionOnGetItem(): void
    {
        $cause = new TestInvalidArgumentException('Cache error');
        $stubCache = $this->createStub(CacheItemPoolInterface::class);
        $stubCache->method('getItem')->willThrowException($cause);

        $cliHelper = $this->createHelper($stubCache);

        try {
            $cliHelper->createToken('test_user');
        } catch (CacheException $thrown) {
            $this->assertSame('Cache error', $thrown->getMessage());
            $this->assertSame($cause, $thrown->getPrevious(), 'Original cause must be chained');

            return;
        }
        $this->fail('Expected CacheException');
    }

    public function testCreateTokenThrowsCacheExceptionOnSecondGetItem(): void
    {
        $stubCacheItem = $this->createStub(CacheItemInterface::class);
        $stubCacheItem->method('isHit')->willReturn(false);
        $stubCacheItem->method('get')->willReturn(null);

        $cause = new TestInvalidArgumentException('Second cache error');
        $stubCache = $this->createStub(CacheItemPoolInterface::class);
        $callCount = 0;
        $stubCache->method('getItem')
            ->willReturnCallback(function () use ($stubCacheItem, $cause, &$callCount) {
                ++$callCount;
                if (1 === $callCount) {
                    return $stubCacheItem;
                }
                throw $cause;
            });
        $stubCache->method('save')->willReturn(true);

        $cliHelper = $this->createHelper($stubCache);

        try {
            $cliHelper->createToken('another_user');
        } catch (CacheException $thrown) {
            $this->assertSame('Second cache error', $thrown->getMessage());
            $this->assertSame($cause, $thrown->getPrevious(), 'Original cause must be chained');

            return;
        }
        $this->fail('Expected CacheException');
    }

    public function testGetUsernameThrowsCacheExceptionOnGetItem(): void
    {
        $cause = new TestInvalidArgumentException('Cache error');
        $stubCache = $this->createStub(CacheItemPoolInterface::class);
        $stubCache->method('getItem')->willThrowException($cause);

        $cliHelper = $this->createHelper($stubCache);

        try {
            $cliHelper->getUsername('some-token');
        } catch (CacheException $thrown) {
            $this->assertSame('Cache error', $thrown->getMessage());
            $this->assertSame($cause, $thrown->getPrevious(), 'Original cause must be chained');

            return;
        }
        $this->fail('Expected CacheException');
    }

    public function testCreateTokenThrowsCacheExceptionOnNonStringCachedToken(): void
    {
        $stubCacheItem = $this->createStub(CacheItemInterface::class);
        $stubCacheItem->method('isHit')->willReturn(true);
        $stubCacheItem->method('get')->willReturn(42);

        $stubCache = $this->createStub(CacheItemPoolInterface::class);
        $stubCache->method('getItem')->willReturn($stubCacheItem);

        $cliHelper = $this->createHelper($stubCache);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Cached token is not a string');

        $cliHelper->createToken('test_user');
    }

    public function testGetUsernameThrowsCacheExceptionOnNonStringCachedUsername(): void
    {
        $stubCacheItem = $this->createStub(CacheItemInterface::class);
        $stubCacheItem->method('isHit')->willReturn(true);
        $stubCacheItem->method('get')->willReturn(42);

        $stubCache = $this->createStub(CacheItemPoolInterface::class);
        $stubCache->method('getItem')->willReturn($stubCacheItem);

        $cliHelper = $this->createHelper($stubCache);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Cached username is not a string');

        $cliHelper->getUsername('some-token');
    }

    public function testGetUsernameThrowsCacheExceptionOnDeleteItem(): void
    {
        $stubCacheItem = $this->createStub(CacheItemInterface::class);
        $stubCacheItem->method('isHit')->willReturn(true);
        $stubCacheItem->method('get')->willReturn('encoded_username');

        $cause = new TestInvalidArgumentException('Delete error');
        $stubCache = $this->createStub(CacheItemPoolInterface::class);
        $stubCache->method('getItem')->willReturn($stubCacheItem);
        $stubCache->method('deleteItem')->willThrowException($cause);

        $cliHelper = $this->createHelper($stubCache);

        try {
            $cliHelper->getUsername('some-token');
        } catch (CacheException $thrown) {
            $this->assertSame('Delete error', $thrown->getMessage());
            $this->assertSame($cause, $thrown->getPrevious(), 'Original cause must be chained');

            return;
        }
        $this->fail('Expected CacheException');
    }
}
