<?php

namespace Mhpdigital\CrossTenantSecurity\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

class CrossTenantRepositoryFactory implements RepositoryFactory
{
    protected array $repositoryList = [];

    public function __construct(
        protected ManagerRegistry $doctrine,
        protected TokenStorageInterface $tokenStorage,
        protected RoleHierarchyInterface $roleHierarchy,
    ) {}

    public function getRepository(EntityManagerInterface $entityManager, string $entityName): EntityRepository
    {
        $repositoryHash = $entityManager->getClassMetadata($entityName)->getName()
            . spl_object_hash($entityManager);

        if (isset($this->repositoryList[$repositoryHash])) {
            return $this->repositoryList[$repositoryHash];
        }

        return $this->repositoryList[$repositoryHash] = $this->createRepository($entityManager, $entityName);
    }

    private function createRepository(EntityManagerInterface $entityManager, string $entityName): EntityRepository
    {
        $metadata = $entityManager->getClassMetadata($entityName);
        $repositoryClassName = $metadata->customRepositoryClassName
            ?: $entityManager->getConfiguration()->getDefaultRepositoryClassName();

        $repo = new $repositoryClassName($this->doctrine);

        if (method_exists($repo, 'setTokenStorage')) {
            $repo->setTokenStorage($this->tokenStorage);
        }

        if (method_exists($repo, 'setRoleHierarchy')) {
            $repo->setRoleHierarchy($this->roleHierarchy);
        }

        return $repo;
    }
}
