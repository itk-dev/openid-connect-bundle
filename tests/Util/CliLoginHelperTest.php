<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Util;

use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class CliLoginHelperTest extends TestCase
{
    // todo test encode and decode key

    public function testCreateTokenAndGetUsername()
    {
        // Create CliLoginHelper
        $cliHelper = new CliLoginHelper();

        $cache = new FilesystemAdapter('cache.app', 3600);

        $cliHelper->setCache($cache);

        // Create and set a token for user test_user
        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);

        // Get username from token created
        $username = $cliHelper->getUsername($token);

        // Check that we get correct username back
        $this->assertEquals($testUser, $username);
    }

    public function testReuseSetTokenRatherThanRemake()
    {
        // Create CliLoginHelper
        $cliHelper = new CliLoginHelper();

        $cache = new FilesystemAdapter('cache.app', 3600);

        $cliHelper->setCache($cache);

        // Create and set a token for user test_user
        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);

        // Set another token
        $token2 = $cliHelper->createToken($testUser);

        // Test that they are the same
        $this->assertEquals($token, $token2);
    }

    public function testThrowExceptionIfTokenDoesNotExist()
    {
        $this->expectException(\Exception::class);
        // Create CliLoginHelper
        $cliHelper = new CliLoginHelper();

        $cache = new FilesystemAdapter('cache.app', 3600);

        $cliHelper->setCache($cache);

        $username = $cliHelper->getUsername('random_gipperish_token');
    }

    public function testTokenIsRemovedAfterUse()
    {
        $this->expectException(\Exception::class);

        // Create CliLoginHelper
        $cliHelper = new CliLoginHelper();

        $cache = new FilesystemAdapter('cache.app', 3600);

        $cliHelper->setCache($cache);

        // Create and set a token for user test_user
        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);

        // Get username from token created
        $username = $cliHelper->getUsername($token);

        //Try again, to ensure its gone
        $username = $cliHelper->getUsername($token);

    }
}
