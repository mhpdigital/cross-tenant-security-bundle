<?php

namespace Mhpdigital\CrossTenantSecurity\Tests\Repository;

use Mhpdigital\CrossTenantSecurity\Repository\CrossTenantRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

class CrossTenantRepositoryTraitTest extends TestCase
{
    private function makeRepo(
        TokenStorageInterface $tokenStorage,
        RoleHierarchyInterface $roleHierarchy,
    ): object {
        $repo = new class {
            use CrossTenantRepository;

            public function highestRole(): string
            {
                return $this->getHighestRole();
            }
        };

        $repo->setTokenStorage($tokenStorage);
        $repo->setRoleHierarchy($roleHierarchy);

        return $repo;
    }

    public function testGetHighestRoleReturnsEmptyStringWhenNoToken(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $roleHierarchy = $this->createMock(RoleHierarchyInterface::class);

        $repo = $this->makeRepo($tokenStorage, $roleHierarchy);

        $this->assertSame('', $repo->highestRole());
    }

    public function testGetHighestRoleReturnsBestRoleByReachability(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getRoleNames')->willReturn(['ROLE_USER', 'ROLE_ADMIN']);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $roleHierarchy = $this->createMock(RoleHierarchyInterface::class);
        $roleHierarchy->method('getReachableRoleNames')
            ->willReturnCallback(fn(array $roles) => match ($roles) {
                ['ROLE_USER']  => ['ROLE_USER'],
                ['ROLE_ADMIN'] => ['ROLE_ADMIN', 'ROLE_USER'],
                default        => $roles,
            });

        $repo = $this->makeRepo($tokenStorage, $roleHierarchy);

        $this->assertSame('ROLE_ADMIN', $repo->highestRole());
    }

    public function testGetHighestRoleThrowsWhenTokenStorageNotInjected(): void
    {
        $repo = new class {
            use CrossTenantRepository;

            public function highestRole(): string
            {
                return $this->getHighestRole();
            }
        };

        $this->expectException(\LogicException::class);
        $repo->highestRole();
    }
}
