<?php

namespace Mhpdigital\CrossTenantSecurity\Repository;

use Doctrine\ORM\QueryBuilder;
use Mhpdigital\CrossTenantSecurity\Security\CrossTenantUserInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

trait CrossTenantRepository
{
    protected TokenStorageInterface $tokenStorage;
    protected RoleHierarchyInterface $roleHierarchy;

    /** @var string Prefix for all join aliases added by security filtering — override if it collides */
    protected string $securityAliasPrefix = 'sec';

    public function setTokenStorage(TokenStorageInterface $tokenStorage): static
    {
        $this->tokenStorage = $tokenStorage;
        return $this;
    }

    public function setRoleHierarchy(RoleHierarchyInterface $roleHierarchy): static
    {
        $this->roleHierarchy = $roleHierarchy;
        return $this;
    }

    public function getTokenStorage(): TokenStorageInterface
    {
        return $this->tokenStorage;
    }

    protected function getHighestRole(): string
    {
        if (!isset($this->tokenStorage)) {
            throw new \LogicException('TokenStorage not injected. Did you register CrossTenantRepositoryFactory as the Doctrine repository_factory?');
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return '';
        }

        $best      = '';
        $bestScore = -1;

        foreach ($token->getRoleNames() as $role) {
            $score = count($this->roleHierarchy->getReachableRoleNames([$role]));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $role;
            }
        }

        return $best;
    }

    protected function getCurrentUser(): ?CrossTenantUserInterface
    {
        if (!isset($this->tokenStorage)) {
            return null;
        }
        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return null;
        }
        $user = $token->getUser();
        return $user instanceof CrossTenantUserInterface ? $user : null;
    }

    protected function getUserId(): mixed
    {
        return $this->getCurrentUser()?->getId();
    }

    /**
     * Returns a QueryBuilder scoped to the current security context.
     *
     * Unauthenticated requests receive a 1=0 WHERE clause and see no rows.
     * All authenticated users pass through — apply your own tenant filtering
     * by overriding this method in the implementing repository.
     *
     * Common multi-tenant SaaS pattern (these are examples — not enforced by this bundle):
     *
     *   ROLE_USER        → can only see what is assigned to them
     *                      (add WHERE owner = :user in your repository override)
     *   ROLE_ADMIN       → can only see what belongs to users at their company
     *                      (join through the owner to their company in your repository override):
     *
     *                      // Aliases are prefixed with $securityAliasPrefix ('sec' by default)
     *                      // to avoid collisions with joins the host repository adds itself.
     *                      $p = $this->securityAliasPrefix;
     *                      $qb->innerJoin('e.user', "{$p}_user")
     *                         ->innerJoin("{$p}_user.company", "{$p}_company")
     *                         ->andWhere("{$p}_company = :{$p}_company")
     *                         ->setParameter("{$p}_company", $this->getCurrentUser()->getCompany());
     *   ROLE_SUPER_ADMIN → cross-tenant access; sees data across all companies
     *                      (no WHERE needed — or use createUnrestrictedQueryBuilder()
     *                      for CLI/background operations)
     *
     * Note: ROLE_USER, ROLE_ADMIN, and ROLE_SUPER_ADMIN are well-established Symfony
     * conventions but carry no special framework-level meaning. This bundle does not
     * check for any specific role name — all filtering logic belongs in your repository override.
     *
     * The 'sec' alias prefix is defined in $securityAliasPrefix and can be overridden
     * per-repository if it collides with an alias your query already uses.
     */
    public function createQueryBuilder($alias, $indexBy = null): QueryBuilder
    {
        $em = $this->getEntityManager();
        $qb = $em->createQueryBuilder()
            ->select($alias)
            ->from($em->getClassMetadata($this->getEntityName())->getName(), $alias, $indexBy);

        if ($this->getHighestRole() === '') {
            $qb->where('1=0');
        }

        return $qb;
    }

    #[\ReturnTypeWillChange]
    public function find($id, $lockMode = null, $lockVersion = null): ?object
    {
        // Locking requires EntityManager::find() — bypass filtering.
        if ($lockMode !== null) {
            return parent::find($id, $lockMode, $lockVersion);
        }

        return $this->findOneBy(['id' => $id]);
    }

    public function findAll(): array
    {
        return $this->findBy([]);
    }

    public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null): array
    {
        $alias = '_e';
        $qb = $this->createQueryBuilder($alias);

        foreach ($criteria as $field => $value) {
            $param = '_c_' . $field;
            if ($value === null) {
                $qb->andWhere("$alias.$field IS NULL");
            } else {
                $qb->andWhere("$alias.$field = :$param")->setParameter($param, $value);
            }
        }

        if ($orderBy) {
            foreach ($orderBy as $field => $dir) {
                $qb->addOrderBy("$alias.$field", $dir);
            }
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        $results = $this->findBy($criteria, $orderBy, 1);
        return $results[0] ?? null;
    }

    public function createUnrestrictedQueryBuilder(string $alias, ?string $indexBy = null): QueryBuilder
    {
        return parent::createQueryBuilder($alias, $indexBy);
    }

    public function persist(object $entity): void
    {
        $this->getEntityManager()->persist($entity);
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }
}
