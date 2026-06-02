<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Util;

use ItkDev\OpenIdConnectBundle\Exception\CacheException;
use ItkDev\OpenIdConnectBundle\Exception\ItkOpenIdConnectBundleException;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\Uuid;

class CliLoginHelperTest extends TestCase
{
    public function testEncodeAndDecode(): void
    {
        $cache = new ArrayAdapter();
        $cliHelper = new CliLoginHelper($cache);

        $randomUsername = Uuid::v4()->toBase32();

        $encodedUsername = $cliHelper->encodeKey($randomUsername);
        $decodedUsername = $cliHelper->decodeKey($encodedUsername);

        $this->assertEquals($randomUsername, $decodedUsername);
    }

    public function testDecodeKeyReturnsInputWhenNotValidBase64(): void
    {
        $cache = new ArrayAdapter();
        $cliHelper = new CliLoginHelper($cache);

        // Strict base64_decode() rejects input with characters outside the
        // base64 alphabet; decodeKey() then returns the value unchanged.
        $notBase64 = 'not valid base64 !!@@';

        $this->assertSame($notBase64, $cliHelper->decodeKey($notBase64));
    }

    public function testThrowExceptionIfTokenDoesNotExist(): void
    {
        $this->expectException(ItkOpenIdConnectBundleException::class);

        $cache = new ArrayAdapter();

        $cliHelper = new CliLoginHelper($cache);

        $cliHelper->getUsername('random_gibberish_token');
    }

    public function testReuseSetTokenRatherThanRemake(): void
    {
        $cache = new ArrayAdapter();

        $cliHelper = new CliLoginHelper($cache);

        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);
        $token2 = $cliHelper->createToken($testUser);

        $this->assertEquals($token, $token2);
    }

    public function testTokenIsRemovedAfterUse(): void
    {
        $cache = new ArrayAdapter();

        $cliHelper = new CliLoginHelper($cache);

        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);

        $username = $cliHelper->getUsername($token);
        $this->assertEquals($testUser, $username);

        $this->expectException(ItkOpenIdConnectBundleException::class);

        $cliHelper->getUsername($token);
    }

    public function testCreateTokenAndGetUsername(): void
    {
        $cache = new ArrayAdapter();

        $cliHelper = new CliLoginHelper($cache);

        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);

        $username = $cliHelper->getUsername($token);

        $this->assertEquals($testUser, $username);
    }

    public function testCreateTokenThrowsCacheExceptionOnGetItem(): void
    {
        $cause = new TestInvalidArgumentException('Cache error');
        $stubCache = $this->createStub(CacheItemPoolInterface::class);
        $stubCache->method('getItem')->willThrowException($cause);

        $cliHelper = new CliLoginHelper($stubCache);

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

        $cliHelper = new CliLoginHelper($stubCache);

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

        $cliHelper = new CliLoginHelper($stubCache);

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

        $cliHelper = new CliLoginHelper($stubCache);

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

        $cliHelper = new CliLoginHelper($stubCache);

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

        $cliHelper = new CliLoginHelper($stubCache);

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
