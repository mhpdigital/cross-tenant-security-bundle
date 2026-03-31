<?php

namespace Mhpdigital\CrossTenantSecurity\Tests\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mhpdigital\CrossTenantSecurity\Repository\CrossTenantRepository;
use Mhpdigital\CrossTenantSecurity\Security\CrossTenantUserInterface;

/**
 * Test double that extends EntityRepository and uses CrossTenantRepository.
 * Bypasses EntityRepository::__construct via a named factory so tests can
 * supply a mock EntityManager without setting up the full Doctrine stack.
 */
class CrossTenantRepositoryTestDouble extends EntityRepository
{
    use CrossTenantRepository;

    public static function create(EntityManagerInterface $em, string $entityName = 'TestEntity'): self
    {
        $instance = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();

        $emProp = new \ReflectionProperty(EntityRepository::class, 'em');
        $emProp->setValue($instance, $em);

        $classProp = new \ReflectionProperty(EntityRepository::class, 'class');
        $metadata = new ClassMetadata($entityName);
        $classProp->setValue($instance, $metadata);

        $nameProp = new \ReflectionProperty(EntityRepository::class, 'entityName');
        $nameProp->setValue($instance, $entityName);

        return $instance;
    }

    // Expose protected methods and properties for testing
    public function highestRole(): string
    {
        return $this->getHighestRole();
    }

    public function currentUser(): ?CrossTenantUserInterface
    {
        return $this->getCurrentUser();
    }

    public function userId(): mixed
    {
        return $this->getUserId();
    }

    public function getSecurityAliasPrefix(): string
    {
        return $this->securityAliasPrefix;
    }
}
