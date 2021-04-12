<?php

namespace ItkDev\OpenIdConnectBundle\Util;

use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;

class CliLoginHelper
{
    private $cache;

    private $itkNamespace;

    public function __construct(AdapterInterface $cache)
    {
        $this->cache = $cache;
        $this->itkNamespace = 'itk-dev-cli-login';
    }

    public function createToken(string $username): string
    {
        $encodedUsername = $this->encodeKey($username);
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
        $usernameItem = $this->cache->getItem($token);
        if (!$usernameItem->isHit()) {
            // username does not exist in the cache
            throw new \Exception('Token does not exist');
        }

        $username = $usernameItem->get();

        // delete both entries from cache
        $this->cache->delete($token);
        $this->cache->delete($username);


        return $this->decodeKey($username);
    }

    public function encodeKey(string $key): string
    {
        // Add namespace to key before encoding
        return base64_encode($this->itkNamespace.$key);
    }

    public function decodeKey(string $encodedKey): string
    {
        // Decode encoded key
        $decodedKeyWithNamespace = base64_decode($encodedKey);

        // Remove namespace
        $key = str_replace($this->itkNamespace, '', $decodedKeyWithNamespace);

        return $key;
    }
}
