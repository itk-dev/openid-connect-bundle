<?php

namespace ItkDev\OpenIdConnectBundle\Util;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Uid\Uuid;

class CliLoginHelper
{
    private $cachePool;

    public function __construct(string $cachePool)
    {
        $this->cachePool = $cachePool;
    }

    public function createToken(string $username): string
    {
        $cache = new FilesystemAdapter($this->cachePool, 3600);

        // Token was not set, create and set it.
        $token = Uuid::v4()->toBase32();

        $test = $cache->getItem($token);
        $test->set($username);
        $cache->save($test);

        return $token;
    }


    public function getUsername(string $token, bool $remove = true): ?string
    {
        $cache = new FilesystemAdapter($this->cachePool, 3600);

        // check if token exists in cache
        $username = $cache->getItem($token);
        if (!$username->isHit()) {
            // username does not exist in the cache
            throw new \Exception('Token does not exist');
        }

        // delete token from cache
        $cache->delete($token);

        // return username
        return $username->get();
    }
}
