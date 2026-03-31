<?php

namespace Mhpdigital\CrossTenantSecurity\Tests\Repository;

use Mhpdigital\CrossTenantSecurity\Tests\Fixtures\MockQueryBuilderTrait;
use Mhpdigital\CrossTenantSecurity\Tests\Fixtures\Repository\OpenAccessTestDouble;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

class OpenAccessRepositoryTest extends TestCase
{
    use MockQueryBuilderTrait;

    public function testCreateQueryBuilderDoesNotAddWhereForUnauthenticatedUser(): void
    {
        [$em, $qb] = $this->mockEmWithQb();
        $repo = OpenAccessTestDouble::create($em);

        $ts = $this->createMock(TokenStorageInterface::class);
        $ts->method('getToken')->willReturn(null);
        $repo->setTokenStorage($ts);
        $repo->setRoleHierarchy($this->createMock(RoleHierarchyInterface::class));

        $qb->expects($this->never())->method('where');

        $repo->createQueryBuilder('e');
    }

    public function testCreateQueryBuilderDoesNotAddWhereForAuthenticatedUser(): void
    {
        [$em, $qb] = $this->mockEmWithQb();
        $repo = OpenAccessTestDouble::create($em);

        $qb->expects($this->never())->method('where');

        $repo->createQueryBuilder('e');
    }

    public function testCreateQueryBuilderReturnsQueryBuilder(): void
    {
        [$em, $qb] = $this->mockEmWithQb();
        $repo = OpenAccessTestDouble::create($em);

        $result = $repo->createQueryBuilder('e');
        $this->assertSame($qb, $result);
    }

    public function testCreateQueryBuilderCallsSelectWithAlias(): void
    {
        [$em, $qb] = $this->mockEmWithQb();
        $repo = OpenAccessTestDouble::create($em);

        $qb->expects($this->once())->method('select')->with('item')->willReturnSelf();

        $repo->createQueryBuilder('item');
    }
}
