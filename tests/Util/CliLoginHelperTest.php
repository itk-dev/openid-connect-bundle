<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Util;

use ItkDev\OpenIdConnectBundle\Exception\ItkOpenIdConnectBundleException;
use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\Uuid;

class CliLoginHelperTest extends TestCase
{
    public function testEncodeAndDecode()
    {
        $cache = new ArrayAdapter();
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
        // Expect an exception to be thrown
        $this->expectException(ItkOpenIdConnectBundleException::class);

        // Testing it works with one adapter
        $cache = new ArrayAdapter();

        // Create CliLoginHelper
        $cliHelper = new CliLoginHelper($cache);

        // Call the function that should throw an error
        $username = $cliHelper->getUsername('random_gibberish_token');
    }

    public function testReuseSetTokenRatherThanRemake()
    {
        // Testing it works with one adapter
        $cache = new ArrayAdapter();

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
        // Testing it works with one adapter
        $cache = new ArrayAdapter();

        // Create CliLoginHelper
        $cliHelper = new CliLoginHelper($cache);

        // Create and set a token for user test_user
        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);

        // Get username from token created
        $username = $cliHelper->getUsername($token);

        // Expect an exception to be thrown the second time we call getUsername
        $this->expectException(ItkOpenIdConnectBundleException::class);

        //Try again, to ensure its gone
        $username = $cliHelper->getUsername($token);
    }

    public function testCreateTokenAndGetUsername()
    {
        // Testing it works with one adapter
        $cache = new ArrayAdapter();

        // Create CliLoginHelper
        $cliHelper = new CliLoginHelper($cache);

        // Create and set a token for user test_user
        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);

        // Get username from token created
        $username = $cliHelper->getUsername($token);

        // Check that we get correct username back
        $this->assertEquals($testUser, $username);
    }
}