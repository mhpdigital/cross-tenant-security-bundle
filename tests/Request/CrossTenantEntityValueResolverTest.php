<?php

namespace Mhpdigital\CrossTenantSecurity\Tests\Request;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Mhpdigital\CrossTenantSecurity\Request\CrossTenantEntityValueResolver;
use Mhpdigital\CrossTenantSecurity\Tests\Fixtures\Repository\OpenAccessTestDouble;
use Mhpdigital\CrossTenantSecurity\Tests\Fixtures\Repository\ProtectedRepository;
use Mhpdigital\CrossTenantSecurity\Tests\Fixtures\Repository\UnprotectedRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CrossTenantEntityValueResolverTest extends TestCase
{
    private function resolve(CrossTenantEntityValueResolver $resolver, Request $request, ArgumentMetadata $argument): array
    {
        return [...$resolver->resolve($request, $argument)];
    }

    private function makeRequest(?string $id): Request
    {
        $request = $this->createMock(Request::class);
        $attributes = $this->createMock(ParameterBag::class);
        $attributes->method('get')->with('id')->willReturn($id);
        $request->attributes = $attributes;
        return $request;
    }

    private function makeArgument(?string $type): ArgumentMetadata
    {
        $arg = $this->createMock(ArgumentMetadata::class);
        $arg->method('getType')->willReturn($type);
        return $arg;
    }

    private function makeEm(bool $hasMetadata = true, ?object $repo = null): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);

        if (!$hasMetadata) {
            $em->method('getClassMetadata')->willThrowException(new \Exception('No mapping'));
        } else {
            $metadata = $this->getMockBuilder(ClassMetadata::class)
                ->disableOriginalConstructor()
                ->getMock();
            $em->method('getClassMetadata')->willReturn($metadata);
        }

        if ($repo !== null) {
            $em->method('getRepository')->willReturn($repo);
        }

        return $em;
    }

    private function makeRepoWithQueryResult(?object $entity): ProtectedRepository
    {
        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($entity);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $repo = $this->getMockBuilder(ProtectedRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $repo->method('createQueryBuilder')->willReturn($qb);

        return $repo;
    }

    // -------------------------------------------------------------------------
    // Early returns
    // -------------------------------------------------------------------------

    public function testReturnsEmptyWhenArgumentTypeIsNull(): void
    {
        $resolver = new CrossTenantEntityValueResolver($this->createMock(EntityManagerInterface::class));
        $this->assertSame([], $this->resolve($resolver, $this->makeRequest('1'), $this->makeArgument(null)));
    }

    public function testReturnsEmptyWhenClassDoesNotExist(): void
    {
        $resolver = new CrossTenantEntityValueResolver($this->createMock(EntityManagerInterface::class));
        $this->assertSame([], $this->resolve($resolver, $this->makeRequest('1'), $this->makeArgument('NonExistent\Class')));
    }

    public function testReturnsEmptyWhenClassHasNoDoctrineMetadata(): void
    {
        $resolver = new CrossTenantEntityValueResolver($this->makeEm(hasMetadata: false));
        $this->assertSame([], $this->resolve($resolver, $this->makeRequest('1'), $this->makeArgument(\stdClass::class)));
    }

    public function testReturnsEmptyWhenRepoDoesNotUseCrossTenantTrait(): void
    {
        $unprotectedRepo = $this->getMockBuilder(UnprotectedRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $resolver = new CrossTenantEntityValueResolver($this->makeEm(hasMetadata: true, repo: $unprotectedRepo));
        $this->assertSame([], $this->resolve($resolver, $this->makeRequest('1'), $this->makeArgument(\stdClass::class)));
    }

    public function testReturnsEmptyWhenIdAttributeIsMissing(): void
    {
        $repo = $this->getMockBuilder(ProtectedRepository::class)->disableOriginalConstructor()->getMock();
        $resolver = new CrossTenantEntityValueResolver($this->makeEm(hasMetadata: true, repo: $repo));
        $this->assertSame([], $this->resolve($resolver, $this->makeRequest(null), $this->makeArgument(\stdClass::class)));
    }

    // -------------------------------------------------------------------------
    // Entity resolution
    // -------------------------------------------------------------------------

    public function testThrowsNotFoundHttpExceptionWhenEntityNotFound(): void
    {
        $resolver = new CrossTenantEntityValueResolver(
            $this->makeEm(hasMetadata: true, repo: $this->makeRepoWithQueryResult(null))
        );
        $this->expectException(NotFoundHttpException::class);
        $this->resolve($resolver, $this->makeRequest('99'), $this->makeArgument(\stdClass::class));
    }

    public function testReturnsEntityWhenFound(): void
    {
        $entity = new \stdClass();
        $resolver = new CrossTenantEntityValueResolver(
            $this->makeEm(hasMetadata: true, repo: $this->makeRepoWithQueryResult($entity))
        );
        $this->assertSame([$entity], $this->resolve($resolver, $this->makeRequest('1'), $this->makeArgument(\stdClass::class)));
    }

    public function testPassesIdToQueryBuilder(): void
    {
        $entity = new \stdClass();

        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($entity);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('andWhere')->willReturnSelf();
        $qb->expects($this->once())->method('setParameter')->with('id', '42')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $repo = $this->getMockBuilder(ProtectedRepository::class)->disableOriginalConstructor()->getMock();
        $repo->method('createQueryBuilder')->willReturn($qb);

        $resolver = new CrossTenantEntityValueResolver($this->makeEm(hasMetadata: true, repo: $repo));
        $this->resolve($resolver, $this->makeRequest('42'), $this->makeArgument(\stdClass::class));
    }

    public function testWorkWithOpenAccessRepository(): void
    {
        $entity = new \stdClass();

        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($entity);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $openRepo = $this->getMockBuilder(OpenAccessTestDouble::class)->disableOriginalConstructor()->getMock();
        $openRepo->method('createQueryBuilder')->willReturn($qb);

        $resolver = new CrossTenantEntityValueResolver($this->makeEm(hasMetadata: true, repo: $openRepo));
        $result = $this->resolve($resolver, $this->makeRequest('1'), $this->makeArgument(\stdClass::class));
        $this->assertSame([$entity], $result);
    }

    public function testStringIdIsPassedThroughAsIs(): void
    {
        $entity = new \stdClass();

        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($entity);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('andWhere')->willReturnSelf();
        $qb->expects($this->once())->method('setParameter')->with('id', 'abc-uuid')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $repo = $this->getMockBuilder(ProtectedRepository::class)->disableOriginalConstructor()->getMock();
        $repo->method('createQueryBuilder')->willReturn($qb);

        $resolver = new CrossTenantEntityValueResolver($this->makeEm(hasMetadata: true, repo: $repo));
        $this->resolve($resolver, $this->makeRequest('abc-uuid'), $this->makeArgument(\stdClass::class));
    }
}
