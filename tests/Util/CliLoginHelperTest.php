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
        $stubCache = $this->createStub(CacheItemPoolInterface::class);
        $stubCache->method('getItem')
            ->willThrowException(new TestInvalidArgumentException('Cache error'));

        $cliHelper = new CliLoginHelper($stubCache);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Cache error');

        $cliHelper->createToken('test_user');
    }

    public function testCreateTokenThrowsCacheExceptionOnSecondGetItem(): void
    {
        $stubCacheItem = $this->createStub(CacheItemInterface::class);
        $stubCacheItem->method('isHit')->willReturn(false);
        $stubCacheItem->method('get')->willReturn(null);

        $stubCache = $this->createStub(CacheItemPoolInterface::class);
        $callCount = 0;
        $stubCache->method('getItem')
            ->willReturnCallback(function () use ($stubCacheItem, &$callCount) {
                ++$callCount;
                if (1 === $callCount) {
                    return $stubCacheItem;
                }
                throw new TestInvalidArgumentException('Second cache error');
            });
        $stubCache->method('save')->willReturn(true);

        $cliHelper = new CliLoginHelper($stubCache);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Second cache error');

        $cliHelper->createToken('another_user');
    }

    public function testGetUsernameThrowsCacheExceptionOnGetItem(): void
    {
        $stubCache = $this->createStub(CacheItemPoolInterface::class);
        $stubCache->method('getItem')
            ->willThrowException(new TestInvalidArgumentException('Cache error'));

        $cliHelper = new CliLoginHelper($stubCache);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Cache error');

        $cliHelper->getUsername('some-token');
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

        $stubCache = $this->createStub(CacheItemPoolInterface::class);
        $stubCache->method('getItem')->willReturn($stubCacheItem);
        $stubCache->method('deleteItem')
            ->willThrowException(new TestInvalidArgumentException('Delete error'));

        $cliHelper = new CliLoginHelper($stubCache);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Delete error');

        $cliHelper->getUsername('some-token');
    }
}
