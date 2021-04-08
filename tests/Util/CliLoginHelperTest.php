<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Util;

use ItkDev\OpenIdConnectBundle\Util\CliLoginHelper;
use PHPUnit\Framework\TestCase;

class CliLoginHelperTest extends TestCase
{
    public function testCreateTokenAndGetUsername()
    {
        // Create CliLoginHelper
        $cliHelper = new CliLoginHelper('cache.app');

        // Create and set a token for user test_user
        $testUser = 'test_user';
        $token = $cliHelper->createToken($testUser);

        // Get username from token created
        $username = $cliHelper->getUsername($token);

        // Check that we get correct username back
        $this->assertEquals($testUser, $username);
    }

    // todo Test reuse token

    // todo Test exception
}