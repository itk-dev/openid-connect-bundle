<?php

namespace ItkDev\OpenIdConnectBundle\Util;

use ItkDev\OpenIdConnectBundle\Exception\CacheException;
use ItkDev\OpenIdConnectBundle\Exception\TokenNotFoundException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Helper class for CLI login.
 */
class CliLoginHelper
{
    private const ITK_NAMESPACE = 'itk-dev-cli-login';

    public function __construct(
        private readonly CacheItemPoolInterface $cache
    ) {
    }

    /**
     * Creates login token for CLI login.
     *
     * @throws CacheException
     */
    public function createToken(string $username): string
    {
        $encodedUsername = $this->encodeKey($username);
        $token = Uuid::v4()->toBase32();

        // Add username => token to make sure no username has more than one token
        try {
            $revCacheItem = $this->cache->getItem($encodedUsername);
        } catch (InvalidArgumentException $e) {
            throw new CacheException($e->getMessage());
        }

        if ($revCacheItem->isHit()) {
            return $revCacheItem->get();
        }
        $revCacheItem->set($token);
        $this->cache->save($revCacheItem);

        // Add token => username
        try {
            $cacheItem = $this->cache->getItem($token);
        } catch (InvalidArgumentException $e) {
            throw new CacheException($e->getMessage());
        }

        $cacheItem->set($encodedUsername);
        $this->cache->save($cacheItem);

        return $token;
    }

    /**
     * Gets username from login token.
     *
     * @throws TokenNotFoundException
     * @throws CacheException
     */
    public function getUsername(string $token): ?string
    {
        try {
            $usernameItem = $this->cache->getItem($token);
        } catch (InvalidArgumentException $e) {
            throw new CacheException($e->getMessage());
        }

        if (!$usernameItem->isHit()) {
            throw new TokenNotFoundException('Token does not exist');
        }

        $username = $usernameItem->get();

        // Delete both entries from cache
        try {
            $this->cache->deleteItem($token);
            $this->cache->deleteItem($username);
        } catch (InvalidArgumentException $e) {
            throw new CacheException($e->getMessage());
        }

        return $this->decodeKey($username);
    }

    public function encodeKey(string $key): string
    {
        // Add namespace to key before encoding
        return base64_encode(self::ITK_NAMESPACE.$key);
    }

    public function decodeKey(string $encodedKey): string
    {
        $decodedKeyWithNamespace = base64_decode($encodedKey);

        // Remove namespace
        $key = str_replace(self::ITK_NAMESPACE, '', $decodedKeyWithNamespace);

        return $key;
    }
}
