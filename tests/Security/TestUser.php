<?php

namespace ItkDev\OpenIdConnectBundle\Tests\Security;

use Symfony\Component\Security\Core\User\UserInterface;

class TestUser implements UserInterface
{
    public function __construct(
        private readonly string $email,
    ) {
    }

    public function getRoles(): array
    {
        // TODO: Implement getRoles() method.
    }

    public function eraseCredentials()
    {
        // TODO: Implement eraseCredentials() method.
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
