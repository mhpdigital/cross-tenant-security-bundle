<?php

namespace Mhpdigital\CrossTenantSecurity\Tests\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;

/**
 * Shared helper for tests that need a mocked EntityManager + QueryBuilder chain.
 */
trait MockQueryBuilderTrait
{
    /**
     * Returns a mock EntityManager whose createQueryBuilder() and getClassMetadata()
     * return a chainable QueryBuilder mock.
     *
     * @return array{EntityManagerInterface, QueryBuilder}
     */
    private function mockEmWithQb(string $entityName = 'TestEntity'): array
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();

        $metadata = $this->getMockBuilder(ClassMetadata::class)
            ->disableOriginalConstructor()
            ->getMock();
        $metadata->method('getName')->willReturn($entityName);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);
        $em->method('getClassMetadata')->willReturn($metadata);

        return [$em, $qb];
    }
}
