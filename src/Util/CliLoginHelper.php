<?php

namespace ItkDev\OpenIdConnectBundle\Util;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Uid\Uuid;

class CliLoginHelper
{
    private $cache;

    public function __construct(string $cachePool)
    {
        $this->cache = new FilesystemAdapter($cachePool, 3600);
    }

    public function createToken(string $username): string
    {
        $encodedUsername = base64_encode($username);

        $token = Uuid::v4()->toBase32();

        // Add username => token to make sure no username has more than one token
        $revCacheItem = $this->cache->getItem($encodedUsername);

        if ($revCacheItem->isHit()) {
            return $revCacheItem->get();
        }
        $revCacheItem->set($token);
        $this->cache->save($revCacheItem);

        // Add token => username
        $cacheItem = $this->cache->getItem($token);
        $cacheItem->set($encodedUsername);
        $this->cache->save($cacheItem);

        return $token;
    }


    public function getUsername(string $token): ?string
    {
        // check if token exists in cache
        $username = $this->cache->getItem($token);
        if (!$username->isHit()) {
            // username does not exist in the cache
            throw new \Exception('Token does not exist');
        }

        // delete both entries from cache
        $this->cache->delete($token);
        $this->cache->delete($username->get());

        return base64_decode($username->get());
    }

    // Add 'namespace' itk-dev...

    // private function encodeKey(string $key)

    // private function decodeKey(string $key)
}
