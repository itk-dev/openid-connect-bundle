<?php

namespace ItkDev\OpenIdConnectBundle\Util;

use ItkDev\OpenIdConnectBundle\Exception\TokenNotFoundException;
use Symfony\Component\Uid\Uuid;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Helper class for CLI login.
 */
class CliLoginHelper
{
    private const ITK_NAMESPACE = 'itk-dev-cli-login';

    /**
     * @var CacheItemPoolInterface
     */
    private $cache;

    public function __construct(CacheItemPoolInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Creates login token for CLI login.
     *
     * @param string $username
     * @return string
     */
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


    /**
     * Gets username from login token.
     *
     * @param string $token
     * @return string|null
     * @throws TokenNotFoundException
     */
    public function getUsername(string $token): ?string
    {
        $usernameItem = $this->cache->getItem($token);

        if (!$usernameItem->isHit()) {
            throw new TokenNotFoundException('Token does not exist');
        }

        $username = $usernameItem->get();

        // Delete both entries from cache
        $this->cache->deleteItem($token);
        $this->cache->deleteItem($username);

        return $this->decodeKey($username);
    }

    /**
     * @param string $key
     * @return string
     */
    public function encodeKey(string $key): string
    {
        // Add namespace to key before encoding
        return base64_encode(self::ITK_NAMESPACE . $key);
    }

    /**
     * @param string $encodedKey
     * @return string
     */
    public function decodeKey(string $encodedKey): string
    {
        $decodedKeyWithNamespace = base64_decode($encodedKey);

        // Remove namespace
        $key = str_replace(self::ITK_NAMESPACE, '', $decodedKeyWithNamespace);

        return $key;
    }
}
