<?php

namespace Mhpdigital\CrossTenantSecurity\Tests\Repository;

use Mhpdigital\CrossTenantSecurity\Tests\Fixtures\MockQueryBuilderTrait;
use Mhpdigital\CrossTenantSecurity\Tests\Fixtures\Repository\AdminOnlyTestDouble;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

class AdminOnlyAccessRepositoryTest extends TestCase
{
    use MockQueryBuilderTrait;

    private function makeRepo(string $highestRole, bool $cli = false): array
    {
        [$em, $qb] = $this->mockEmWithQb();
        $repo = AdminOnlyTestDouble::create($em);
        $repo->setSimulateCli($cli);

        if ($highestRole === '') {
            $ts = $this->createMock(TokenStorageInterface::class);
            $ts->method('getToken')->willReturn(null);
            $repo->setTokenStorage($ts);
            $repo->setRoleHierarchy($this->createMock(RoleHierarchyInterface::class));
        } else {
            $token = $this->createMock(TokenInterface::class);
            $token->method('getRoleNames')->willReturn([$highestRole]);

            $ts = $this->createMock(TokenStorageInterface::class);
            $ts->method('getToken')->willReturn($token);

            $rh = $this->createMock(RoleHierarchyInterface::class);
            $rh->method('getReachableRoleNames')->willReturnCallback(fn(array $roles) => [$roles[0]]);

            $repo->setTokenStorage($ts);
            $repo->setRoleHierarchy($rh);
        }

        return [$repo, $qb];
    }

    // -------------------------------------------------------------------------
    // Non-CLI: role enforcement
    // -------------------------------------------------------------------------

    public function testSuperAdminGetsCleanQueryBuilder(): void
    {
        [$repo, $qb] = $this->makeRepo('ROLE_SUPER_ADMIN');
        $qb->expects($this->never())->method('where');
        $repo->createQueryBuilder('e');
    }

    public function testRoleUserGetsBlockedQueryBuilder(): void
    {
        [$repo, $qb] = $this->makeRepo('ROLE_USER');
        $qb->expects($this->once())->method('where')->with('1=0')->willReturnSelf();
        $repo->createQueryBuilder('e');
    }

    public function testRoleAdminGetsBlockedQueryBuilder(): void
    {
        [$repo, $qb] = $this->makeRepo('ROLE_ADMIN');
        $qb->expects($this->once())->method('where')->with('1=0')->willReturnSelf();
        $repo->createQueryBuilder('e');
    }

    public function testUnauthenticatedGetsBlockedQueryBuilder(): void
    {
        [$repo, $qb] = $this->makeRepo('');
        $qb->expects($this->once())->method('where')->with('1=0')->willReturnSelf();
        $repo->createQueryBuilder('e');
    }

    // -------------------------------------------------------------------------
    // CLI bypass
    // -------------------------------------------------------------------------

    public function testCliModeReturnsCleanQueryBuilderForSuperAdmin(): void
    {
        [$repo, $qb] = $this->makeRepo('ROLE_SUPER_ADMIN', cli: true);
        $qb->expects($this->never())->method('where');
        $repo->createQueryBuilder('e');
    }

    public function testCliModeReturnsCleanQueryBuilderForNonAdminRole(): void
    {
        [$repo, $qb] = $this->makeRepo('ROLE_USER', cli: true);
        // CLI bypasses role check — no WHERE clause added
        $qb->expects($this->never())->method('where');
        $repo->createQueryBuilder('e');
    }

    public function testCliModeReturnsCleanQueryBuilderWhenUnauthenticated(): void
    {
        [$repo, $qb] = $this->makeRepo('', cli: true);
        $qb->expects($this->never())->method('where');
        $repo->createQueryBuilder('e');
    }

    // -------------------------------------------------------------------------
    // Return value
    // -------------------------------------------------------------------------

    public function testCreateQueryBuilderReturnsQueryBuilder(): void
    {
        [$repo, $qb] = $this->makeRepo('ROLE_SUPER_ADMIN');
        $this->assertSame($qb, $repo->createQueryBuilder('e'));
    }
}
