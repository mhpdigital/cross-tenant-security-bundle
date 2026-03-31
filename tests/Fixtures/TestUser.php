<?php

namespace Mhpdigital\CrossTenantSecurity\Tests\Fixtures;

use Mhpdigital\CrossTenantSecurity\Security\CrossTenantUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A test user that implements both UserInterface and CrossTenantUserInterface.
 */
class TestUser implements UserInterface, CrossTenantUserInterface
{
    public function __construct(private readonly int $id = 1) {}

    public function getId(): int
    {
        return $this->id;
    }

    public function getRoles(): array
    {
        return [];
    }

    public function getPassword(): ?string
    {
        return null;
    }

    public function getUserIdentifier(): string
    {
        return 'test_user_' . $this->id;
    }

    public function eraseCredentials(): void {}
}
