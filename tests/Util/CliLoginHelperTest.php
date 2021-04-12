<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Util;

use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;

class CliLoginHelperTest extends TestCase
{
    public function testEncodeAndDecode()
    {
        $cache = $this->createMock(AdapterInterface::class);
        // Create CliLoginHelper
        $cliHelper = new CliLoginHelper($cache);

        $randomUsername = Uuid::v4()->toBase32();

        // Encode the username
        $encodedUsername = $cliHelper->encodeKey($randomUsername);

        // Decode the encoded username
        $decodedUsername = $cliHelper->decodeKey($encodedUsername);

        // Assert equals
        $this->assertEquals($randomUsername, $decodedUsername);
    }

    public function testThrowExceptionIfTokenDoesNotExist()
    {
        // todo figure out how to test this 'generally'
        $this->expectException(\Exception::class);

        //$cache = new FilesystemAdapter('cache.app', 3600);
        $cache = $this->createMock(AdapterInterface::class);

        // todo the beneath seem not testing to me
        // todo cant 'mock' CacheItem
        $cache
            ->expects($this->once())
            ->method('getItem')
            ->willThrowException(new \Exception());

        // Create CliLoginHelper
        $cliHelper = new CliLoginHelper($cache);

        $username = $cliHelper->getUsername('random_gibberish_token');
    }

    public function testReuseSetTokenRatherThanRemake()
    {
        // todo figure out how to test this 'generally'
        $cache = new FilesystemAdapter('cache.app', 3600);

        // Create CliLoginHelper
        $cliHelper = new CliLoginHelper($cache);

        // Create and set a token for user test_user
        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);

        // Set another token
        $token2 = $cliHelper->createToken($testUser);

        // Test that they are the same
        $this->assertEquals($token, $token2);
    }

    public function testTokenIsRemovedAfterUse()
    {
        // todo figure out how to test this 'generally'
        $this->expectException(\Exception::class);

        $cache = new FilesystemAdapter('cache.app', 3600);

        // Create CliLoginHelper
        $cliHelper = new CliLoginHelper($cache);

        // Create and set a token for user test_user
        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);

        // Get username from token created
        $username = $cliHelper->getUsername($token);

        //Try again, to ensure its gone
        $username = $cliHelper->getUsername($token);
    }

    /*public function testCreateTokenAndGetUsername()
    {
        // Create a mock for testing purposes
        $cache = $this->createMock(CacheInterface::class);

        // Create CliLoginHelper
        $cliHelper = new CliLoginHelper($cache);

        //$cache = new FilesystemAdapter('cache.app', 3600);

        //$cliHelper->setCache($cache);

        // Create and set a token for user test_user
        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);

        // Get username from token created
        $username = $cliHelper->getUsername($token);

        // Check that we get correct username back
        $this->assertEquals($testUser, $username);
    }*/
}
