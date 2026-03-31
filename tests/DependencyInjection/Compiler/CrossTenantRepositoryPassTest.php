<?php

namespace Mhpdigital\CrossTenantSecurity\Tests\DependencyInjection\Compiler;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\EntityManager;
use Mhpdigital\CrossTenantSecurity\DependencyInjection\Compiler\CrossTenantRepositoryPass;
use Mhpdigital\CrossTenantSecurity\Tests\Fixtures\Repository\AdminOnlyFixtureRepository;
use Mhpdigital\CrossTenantSecurity\Tests\Fixtures\Repository\OpenAccessFixtureRepository;
use Mhpdigital\CrossTenantSecurity\Tests\Fixtures\Repository\ProtectedRepository;
use Mhpdigital\CrossTenantSecurity\Tests\Fixtures\Repository\UnprotectedRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

class CrossTenantRepositoryPassTest extends TestCase
{
    private function buildContainer(string $class): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $definition = new Definition($class);
        $container->setDefinition('app.repository.test', $definition);
        return $container;
    }

    public function testProtectedRepositoryCompilesPasses(): void
    {
        $container = $this->buildContainer(ProtectedRepository::class);
        (new CrossTenantRepositoryPass())->process($container);
        $this->assertTrue(true); // no exception
    }

    public function testOpenAccessRepositoryCompilesPasses(): void
    {
        $container = $this->buildContainer(OpenAccessFixtureRepository::class);
        (new CrossTenantRepositoryPass())->process($container);
        $this->assertTrue(true);
    }

    public function testAdminOnlyRepositoryCompilesPasses(): void
    {
        $container = $this->buildContainer(AdminOnlyFixtureRepository::class);
        (new CrossTenantRepositoryPass())->process($container);
        $this->assertTrue(true);
    }

    public function testUnprotectedRepositoryThrowsOnCompile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/UnprotectedRepository/');
        $this->expectExceptionMessageMatches('/CrossTenantRepository/');

        $container = $this->buildContainer(UnprotectedRepository::class);
        (new CrossTenantRepositoryPass())->process($container);
    }

    public function testVendorClassWithoutRepositorySegmentIsIgnored(): void
    {
        // Doctrine\ORM\EntityRepository has no \Repository\ namespace segment,
        // so it is treated as vendor code and must not trigger the enforcement check.
        $container = new ContainerBuilder();
        $definition = new Definition(EntityRepository::class);
        $container->setDefinition('vendor.repo', $definition);

        (new CrossTenantRepositoryPass())->process($container);
        $this->assertTrue(true); // must not throw
    }

    public function testNonRepositoryServiceIsIgnored(): void
    {
        $container = new ContainerBuilder();
        $definition = new Definition(\stdClass::class);
        $container->setDefinition('app.service', $definition);

        (new CrossTenantRepositoryPass())->process($container);
        $this->assertTrue(true);
    }
}
