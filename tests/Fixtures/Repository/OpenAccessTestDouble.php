<?php

namespace Mhpdigital\CrossTenantSecurity\Tests\Fixtures\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mhpdigital\CrossTenantSecurity\Repository\OpenAccessRepository;

class OpenAccessTestDouble extends EntityRepository
{
    use OpenAccessRepository;

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
}
