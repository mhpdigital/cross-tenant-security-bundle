<?php

namespace Mhpdigital\CrossTenantSecurity\Tests\DependencyInjection\Compiler;

use Mhpdigital\CrossTenantSecurity\DependencyInjection\Compiler\CrossTenantRepositoryPass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the protected resolveEntityClass() method via a public subclass.
 */
class ResolveEntityClassTest extends TestCase
{
    private CrossTenantRepositoryPass $pass;

    protected function setUp(): void
    {
        $this->pass = new class extends CrossTenantRepositoryPass {
            public function resolve(string $class): string
            {
                return $this->resolveEntityClass($class);
            }
        };
    }

    public function testStandardSymfonyConvention(): void
    {
        $this->assertSame(
            'App\Entity\Foo',
            $this->pass->resolve('App\Repository\FooRepository')
        );
    }

    public function testDeepNamespace(): void
    {
        $this->assertSame(
            'My\Deep\Entity\Foo',
            $this->pass->resolve('My\Deep\Repository\FooRepository')
        );
    }

    public function testBundleNamespace(): void
    {
        $this->assertSame(
            'Acme\Bundle\Entity\Foo',
            $this->pass->resolve('Acme\Bundle\Repository\FooRepository')
        );
    }

    public function testClassWithRepositorySuffixButNoSegment(): void
    {
        // No \Repository\ segment — falls back to stripping suffix
        $this->assertSame(
            'App\FooBar',
            $this->pass->resolve('App\FooBarRepository')
        );
    }

    public function testClassWithNoRepositorySuffixAndNoSegment(): void
    {
        // Neither segment nor suffix — returned unchanged
        $this->assertSame(
            'App\SomeService',
            $this->pass->resolve('App\SomeService')
        );
    }

    public function testRepositorySegmentReplacedBeforeSuffixStrip(): void
    {
        // Segment replacement takes precedence over fallback suffix strip
        $this->assertSame(
            'App\Entity\User',
            $this->pass->resolve('App\Repository\UserRepository')
        );
    }

    public function testShortNamespace(): void
    {
        $this->assertSame(
            'Entity\Foo',
            $this->pass->resolve('Repository\FooRepository')
        );
    }
}
