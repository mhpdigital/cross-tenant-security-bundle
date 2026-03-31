<?php

namespace Mhpdigital\CrossTenantSecurity\Tests\Fixtures\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mhpdigital\CrossTenantSecurity\Repository\AdminOnlyAccessRepository;

class AdminOnlyTestDouble extends EntityRepository
{
    use AdminOnlyAccessRepository;

    private bool $simulateCli = false;

    public static function create(EntityManagerInterface $em, string $entityName = 'TestEntity'): self
    {
        $instance = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();

        $emProp = new \ReflectionProperty(EntityRepository::class, 'em');
        $emProp->setValue($instance, $em);

        $classProp = new \ReflectionProperty(EntityRepository::class, 'class');
        $classProp->setValue($instance, new ClassMetadata($entityName));

        $nameProp = new \ReflectionProperty(EntityRepository::class, 'entityName');
        $nameProp->setValue($instance, $entityName);

        return $instance;
    }

    public function setSimulateCli(bool $value): void
    {
        $this->simulateCli = $value;
    }

    protected function isCli(): bool
    {
        return $this->simulateCli;
    }
}
