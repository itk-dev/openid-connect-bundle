<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

use Symfony\Component\Security\Core\User\UserInterface;

class TestUser implements UserInterface
{
    /** @var non-empty-string */
    private readonly string $email;

    public function __construct(string $email)
    {
        if ('' === $email) {
            throw new \InvalidArgumentException('TestUser requires a non-empty email.');
        }
        $this->email = $email;
    }

    public function getRoles(): array
    {
        return [];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword()
    {
        // TODO: Implement getPassword() method.
    }

    public function getSalt()
    {
        // TODO: Implement getSalt() method.
    }

    public function getUsername()
    {
        // TODO: Implement getUsername() method.
    }
}
