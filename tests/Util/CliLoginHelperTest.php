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
        $cliHelper = new CliLoginHelper($cache);

        $randomUsername = Uuid::v4()->toBase32();

        $encodedUsername = $cliHelper->encodeKey($randomUsername);
        $decodedUsername = $cliHelper->decodeKey($encodedUsername);

        $this->assertEquals($randomUsername, $decodedUsername);
    }

    public function testThrowExceptionIfTokenDoesNotExist()
    {
        $this->expectException(ItkOpenIdConnectBundleException::class);

        $cache = new ArrayAdapter();

        $cliHelper = new CliLoginHelper($cache);

        $username = $cliHelper->getUsername('random_gibberish_token');
    }

    public function testReuseSetTokenRatherThanRemake()
    {
        $cache = new ArrayAdapter();

        $cliHelper = new CliLoginHelper($cache);

        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);
        $token2 = $cliHelper->createToken($testUser);

        $this->assertEquals($token, $token2);
    }

    public function testTokenIsRemovedAfterUse()
    {
        $cache = new ArrayAdapter();

        $cliHelper = new CliLoginHelper($cache);

        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);

        $username = $cliHelper->getUsername($token);

        $this->expectException(ItkOpenIdConnectBundleException::class);

        $username = $cliHelper->getUsername($token);
    }

    public function testCreateTokenAndGetUsername()
    {
        $cache = new ArrayAdapter();

        $cliHelper = new CliLoginHelper($cache);

        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);

        $username = $cliHelper->getUsername($token);

        $this->assertEquals($testUser, $username);
    }
}
