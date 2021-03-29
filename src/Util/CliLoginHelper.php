<?php

namespace ItkDev\OpenIdConnectBundle\Util;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Uid\Uuid;

class CliLoginHelper
{
    private $entityManager;
    private $userProvider;

    public function __construct(EntityManagerInterface $entityManager, UserProviderInterface $userProvider)
    {
        $this->entityManager = $entityManager;
        $this->userProvider = $userProvider;
    }

    public function createToken(UserInterface $user)
    {
        // todo...
        $token = Uuid::v4()->toBase32();

        return $user->getUsername();
    }


    public function getUser($token): UserInterface
    {
        // todo...
        // Find user id in 'token' table

        // If user id dont exist - throw error

        // If user id exists, get user from 'user' table
        return $this->userProvider->loadUserByUsername($token);
    }
}
