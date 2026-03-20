<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Util;

use Psr\Cache\InvalidArgumentException as PsrInvalidArgumentException;

class TestInvalidArgumentException extends \InvalidArgumentException implements PsrInvalidArgumentException
{
}
