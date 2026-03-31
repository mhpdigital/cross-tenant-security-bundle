<?php

namespace Mhpdigital\CrossTenantSecurity\Tests\Fixtures;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A user that implements UserInterface but NOT CrossTenantUserInterface.
 * Used to verify getCurrentUser() returns null for incompatible users.
 */
class NonCrossTenantUser implements UserInterface
{
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
        return 'non_cross_tenant_user';
    }

    public function eraseCredentials(): void {}
}
