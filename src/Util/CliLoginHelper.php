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

        $encodedUsername = base64_encode($username);

        // Token was not set, create and set it.
        $token = 'itk-dev-login-token'.Uuid::v4()->toBase32();


        // Add username => token to make sure no username has more than one token
        $revCacheItem = $cache->getItem($encodedUsername);

        if (!$revCacheItem->isHit()) {
            $revCacheItem->set($token);
            $cache->save($revCacheItem);
        } else {
            return $revCacheItem->get();
        }

        // Add token => username
        $cacheItem = $cache->getItem($token);
        $cacheItem->set($encodedUsername);
        $cache->save($cacheItem);

        return $token;
    }


    public function getUsername(string $token): ?string
    {
        $cache = new FilesystemAdapter($this->cachePool, 3600);

        // check if token exists in cache
        $username = $cache->getItem($token);
        if (!$username->isHit()) {
            // username does not exist in the cache
            throw new \Exception('Token does not exist');
        }

        // delete both entries from cache
        $cache->delete($token);
        $cache->delete($username->get());

        return base64_decode($username->get());
    }
}
