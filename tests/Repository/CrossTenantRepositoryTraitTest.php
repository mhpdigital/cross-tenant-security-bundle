<?php

namespace Mhpdigital\CrossTenantSecurity\Tests\Repository;

use Mhpdigital\CrossTenantSecurity\Repository\CrossTenantRepository;
use Mhpdigital\CrossTenantSecurity\Tests\Fixtures\CrossTenantRepositoryTestDouble;
use Mhpdigital\CrossTenantSecurity\Tests\Fixtures\MockQueryBuilderTrait;
use Mhpdigital\CrossTenantSecurity\Tests\Fixtures\NonCrossTenantUser;
use Mhpdigital\CrossTenantSecurity\Tests\Fixtures\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

class CrossTenantRepositoryTraitTest extends TestCase
{
    use MockQueryBuilderTrait;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeTokenStorage(?TokenInterface $token): TokenStorageInterface
    {
        $ts = $this->createMock(TokenStorageInterface::class);
        $ts->method('getToken')->willReturn($token);
        return $ts;
    }

    private function makeRoleHierarchy(array $reachabilityMap = []): RoleHierarchyInterface
    {
        $rh = $this->createMock(RoleHierarchyInterface::class);
        $rh->method('getReachableRoleNames')
            ->willReturnCallback(fn(array $roles) => $reachabilityMap[$roles[0]] ?? $roles);
        return $rh;
    }

    private function makeToken(array $roles): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getRoleNames')->willReturn($roles);
        return $token;
    }

    private function makeTokenWithUser(object $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getRoleNames')->willReturn([]);
        $token->method('getUser')->willReturn($user);
        return $token;
    }

    // -------------------------------------------------------------------------
    // setTokenStorage / setRoleHierarchy / getTokenStorage
    // -------------------------------------------------------------------------

    public function testSetTokenStorageReturnsSelf(): void
    {
        $repo = new class { use CrossTenantRepository; };
        $ts = $this->createMock(TokenStorageInterface::class);
        $this->assertSame($repo, $repo->setTokenStorage($ts));
    }

    public function testSetRoleHierarchyReturnsSelf(): void
    {
        $repo = new class { use CrossTenantRepository; };
        $rh = $this->createMock(RoleHierarchyInterface::class);
        $this->assertSame($repo, $repo->setRoleHierarchy($rh));
    }

    public function testGetTokenStorageReturnsWhatWasSet(): void
    {
        $repo = new class { use CrossTenantRepository; };
        $ts = $this->createMock(TokenStorageInterface::class);
        $repo->setTokenStorage($ts);
        $this->assertSame($ts, $repo->getTokenStorage());
    }

    // -------------------------------------------------------------------------
    // getHighestRole
    // -------------------------------------------------------------------------

    public function testGetHighestRoleThrowsWhenTokenStorageNotInjected(): void
    {
        $repo = new class {
            use CrossTenantRepository;
            public function highest(): string { return $this->getHighestRole(); }
        };
        $this->expectException(\LogicException::class);
        $repo->highest();
    }

    public function testGetHighestRoleReturnsEmptyStringWhenTokenIsNull(): void
    {
        $repo = new class {
            use CrossTenantRepository;
            public function highest(): string { return $this->getHighestRole(); }
        };
        $repo->setTokenStorage($this->makeTokenStorage(null));
        $repo->setRoleHierarchy($this->createMock(RoleHierarchyInterface::class));
        $this->assertSame('', $repo->highest());
    }

    public function testGetHighestRoleReturnsEmptyStringWhenUserHasNoRoles(): void
    {
        $repo = new class {
            use CrossTenantRepository;
            public function highest(): string { return $this->getHighestRole(); }
        };
        $repo->setTokenStorage($this->makeTokenStorage($this->makeToken([])));
        $repo->setRoleHierarchy($this->makeRoleHierarchy());
        $this->assertSame('', $repo->highest());
    }

    public function testGetHighestRoleReturnsSingleRole(): void
    {
        $repo = new class {
            use CrossTenantRepository;
            public function highest(): string { return $this->getHighestRole(); }
        };
        $repo->setTokenStorage($this->makeTokenStorage($this->makeToken(['ROLE_USER'])));
        $repo->setRoleHierarchy($this->makeRoleHierarchy(['ROLE_USER' => ['ROLE_USER']]));
        $this->assertSame('ROLE_USER', $repo->highest());
    }

    public function testGetHighestRoleReturnsBestRoleByReachability(): void
    {
        $repo = new class {
            use CrossTenantRepository;
            public function highest(): string { return $this->getHighestRole(); }
        };
        $repo->setTokenStorage($this->makeTokenStorage($this->makeToken(['ROLE_USER', 'ROLE_ADMIN'])));
        $repo->setRoleHierarchy($this->makeRoleHierarchy([
            'ROLE_USER'  => ['ROLE_USER'],
            'ROLE_ADMIN' => ['ROLE_ADMIN', 'ROLE_USER'],
        ]));
        $this->assertSame('ROLE_ADMIN', $repo->highest());
    }

    public function testGetHighestRolePicksRoleWithMostReachableRoles(): void
    {
        $repo = new class {
            use CrossTenantRepository;
            public function highest(): string { return $this->getHighestRole(); }
        };
        $repo->setTokenStorage($this->makeTokenStorage(
            $this->makeToken(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'])
        ));
        $repo->setRoleHierarchy($this->makeRoleHierarchy([
            'ROLE_USER'        => ['ROLE_USER'],
            'ROLE_ADMIN'       => ['ROLE_ADMIN', 'ROLE_USER'],
            'ROLE_SUPER_ADMIN' => ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN', 'ROLE_USER'],
        ]));
        $this->assertSame('ROLE_SUPER_ADMIN', $repo->highest());
    }

    // -------------------------------------------------------------------------
    // getCurrentUser / getUserId
    // -------------------------------------------------------------------------

    public function testGetCurrentUserReturnsNullWhenTokenStorageNotInjected(): void
    {
        [$em] = $this->mockEmWithQb();
        $repo = CrossTenantRepositoryTestDouble::create($em);
        $this->assertNull($repo->currentUser());
    }

    public function testGetCurrentUserReturnsNullWhenTokenIsNull(): void
    {
        [$em] = $this->mockEmWithQb();
        $repo = CrossTenantRepositoryTestDouble::create($em);
        $repo->setTokenStorage($this->makeTokenStorage(null));
        $this->assertNull($repo->currentUser());
    }

    public function testGetCurrentUserReturnsNullWhenUserDoesNotImplementInterface(): void
    {
        [$em] = $this->mockEmWithQb();
        $repo = CrossTenantRepositoryTestDouble::create($em);
        $repo->setTokenStorage($this->makeTokenStorage($this->makeTokenWithUser(new NonCrossTenantUser())));
        $this->assertNull($repo->currentUser());
    }

    public function testGetCurrentUserReturnsUserWhenImplementingInterface(): void
    {
        [$em] = $this->mockEmWithQb();
        $repo = CrossTenantRepositoryTestDouble::create($em);
        $user = new TestUser(42);
        $repo->setTokenStorage($this->makeTokenStorage($this->makeTokenWithUser($user)));
        $this->assertSame($user, $repo->currentUser());
    }

    public function testGetUserIdReturnsNullWhenNoUser(): void
    {
        [$em] = $this->mockEmWithQb();
        $repo = CrossTenantRepositoryTestDouble::create($em);
        $repo->setTokenStorage($this->makeTokenStorage(null));
        $this->assertNull($repo->userId());
    }

    public function testGetUserIdReturnsUserIdWhenAuthenticated(): void
    {
        [$em] = $this->mockEmWithQb();
        $repo = CrossTenantRepositoryTestDouble::create($em);
        $repo->setTokenStorage($this->makeTokenStorage($this->makeTokenWithUser(new TestUser(99))));
        $this->assertSame(99, $repo->userId());
    }

    // -------------------------------------------------------------------------
    // createQueryBuilder
    // -------------------------------------------------------------------------

    public function testCreateQueryBuilderAddsWhereOneEqualsZeroWhenUnauthenticated(): void
    {
        [$em, $qb] = $this->mockEmWithQb();
        $repo = CrossTenantRepositoryTestDouble::create($em);
        $repo->setTokenStorage($this->makeTokenStorage(null));
        $repo->setRoleHierarchy($this->createMock(RoleHierarchyInterface::class));

        $qb->expects($this->once())->method('where')->with('1=0')->willReturnSelf();

        $repo->createQueryBuilder('e');
    }

    public function testCreateQueryBuilderDoesNotAddWhereWhenAuthenticated(): void
    {
        [$em, $qb] = $this->mockEmWithQb();
        $repo = CrossTenantRepositoryTestDouble::create($em);
        $repo->setTokenStorage($this->makeTokenStorage($this->makeToken(['ROLE_USER'])));
        $repo->setRoleHierarchy($this->makeRoleHierarchy(['ROLE_USER' => ['ROLE_USER']]));

        $qb->expects($this->never())->method('where');

        $repo->createQueryBuilder('e');
    }

    public function testCreateQueryBuilderReturnsQueryBuilder(): void
    {
        [$em, $qb] = $this->mockEmWithQb();
        $repo = CrossTenantRepositoryTestDouble::create($em);
        $repo->setTokenStorage($this->makeTokenStorage($this->makeToken(['ROLE_USER'])));
        $repo->setRoleHierarchy($this->makeRoleHierarchy(['ROLE_USER' => ['ROLE_USER']]));

        $this->assertSame($qb, $repo->createQueryBuilder('e'));
    }

    public function testCreateQueryBuilderPassesAliasAndIndexBy(): void
    {
        [$em, $qb] = $this->mockEmWithQb('App\Entity\Foo');
        $repo = CrossTenantRepositoryTestDouble::create($em, 'App\Entity\Foo');
        $repo->setTokenStorage($this->makeTokenStorage($this->makeToken(['ROLE_USER'])));
        $repo->setRoleHierarchy($this->makeRoleHierarchy(['ROLE_USER' => ['ROLE_USER']]));

        $qb->expects($this->once())->method('select')->with('e')->willReturnSelf();
        $qb->expects($this->once())->method('from')->with('App\Entity\Foo', 'e', 'e.id')->willReturnSelf();

        $repo->createQueryBuilder('e', 'e.id');
    }

    // -------------------------------------------------------------------------
    // createUnrestrictedQueryBuilder
    // -------------------------------------------------------------------------

    public function testCreateUnrestrictedQueryBuilderBypassesSecurityCheck(): void
    {
        [$em, $qb] = $this->mockEmWithQb();
        $repo = CrossTenantRepositoryTestDouble::create($em);
        // No tokenStorage set — createQueryBuilder would throw, createUnrestrictedQueryBuilder must not

        $qb->expects($this->never())->method('where');

        $result = $repo->createUnrestrictedQueryBuilder('e');
        $this->assertNotNull($result);
    }

    // -------------------------------------------------------------------------
    // persist / flush
    // -------------------------------------------------------------------------

    public function testPersistDelegatesToEntityManager(): void
    {
        [$em] = $this->mockEmWithQb();
        $entity = new \stdClass();
        $em->expects($this->once())->method('persist')->with($entity);

        CrossTenantRepositoryTestDouble::create($em)->persist($entity);
    }

    public function testFlushDelegatesToEntityManager(): void
    {
        [$em] = $this->mockEmWithQb();
        $em->expects($this->once())->method('flush');

        CrossTenantRepositoryTestDouble::create($em)->flush();
    }

    // -------------------------------------------------------------------------
    // securityAliasPrefix
    // -------------------------------------------------------------------------

    public function testSecurityAliasPrefixDefaultsToSec(): void
    {
        [$em] = $this->mockEmWithQb();
        $this->assertSame('sec', CrossTenantRepositoryTestDouble::create($em)->getSecurityAliasPrefix());
    }
}
