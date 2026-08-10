<?php

namespace Mhpdigital\CrossTenantSecurity\Repository;

use Doctrine\ORM\QueryBuilder;
use Mhpdigital\CrossTenantSecurity\Security\CrossTenantUserInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

trait CrossTenantRepository
{
    protected TokenStorageInterface $tokenStorage;
    protected RoleHierarchyInterface $roleHierarchy;
    protected ?RequestStack $requestStack = null;

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

    public function setRequestStack(?RequestStack $requestStack): static
    {
        $this->requestStack = $requestStack;
        return $this;
    }

    /**
     * True when there is no HTTP request in flight — i.e. a console command, a
     * Messenger/queue worker, or a cron run. These are trusted local processes that
     * legitimately need cross-tenant access (index builds, migrations, reports), and
     * they carry no security token, so without this they would otherwise be filtered
     * down to nothing.
     *
     * The discriminator is request-presence, NOT php_sapi: a functional test issues a
     * real sub-request, so getCurrentRequest() is non-null there and web security rules
     * still apply. If RequestStack was never injected (older service wiring), we assume
     * a web context so behaviour fails closed rather than open.
     *
     * Role-specific decisions (which role counts as "super admin", per-tenant filtering)
     * still belong in the implementing repository — this only models console vs web.
     */
    protected function isConsoleContext(): bool
    {
        return isset($this->requestStack) && $this->requestStack->getCurrentRequest() === null;
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
     * This method is FINAL and owns the two things every repository must get right:
     * the base builder, and the console-context guarantee. Repositories contribute
     * their filtering through two hooks instead of re-implementing this method:
     *
     *   {@see applyContentFilter()}  — optional. Applied in EVERY context, console
     *                                  included. For always-on row filters such as
     *                                  soft-delete, which must never be bypassed.
     *   {@see applyTenantScope()}    — required. Applied ONLY in a web context. This
     *                                  is the access gate; console/worker/cron skips it.
     *
     * Console commands, queue workers and cron runs (no HTTP request in flight —
     * see {@see isConsoleContext()}) are trusted local processes and receive FULL
     * access automatically — you no longer need createUnrestrictedQueryBuilder() for
     * CLI/background work. Because the bypass lives here rather than in each
     * repository, it cannot be forgotten: a repository that omitted it used to
     * collapse to getHighestRole()==='' → 1=0 → zero rows in every CLI job.
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
     *                      (no WHERE needed). CLI/background runs are handled
     *                      automatically via console-context detection; use
     *                      createUnrestrictedQueryBuilder() only for an explicit,
     *                      context-independent bypass.
     *
     * Note: ROLE_USER, ROLE_ADMIN, and ROLE_SUPER_ADMIN are well-established Symfony
     * conventions but carry no special framework-level meaning. This bundle does not
     * check for any specific role name — all filtering logic belongs in your hook.
     *
     * The 'sec' alias prefix is defined in $securityAliasPrefix and can be overridden
     * per-repository if it collides with an alias your query already uses.
     */
    final public function createQueryBuilder($alias, $indexBy = null): QueryBuilder
    {
        $qb = $this->applyContentFilter(parent::createQueryBuilder($alias, $indexBy), $alias);

        // Console commands / queue workers / cron run as trusted local processes with no
        // HTTP request and no token — grant full access (this is what callers previously
        // had to spell out with createUnrestrictedQueryBuilder()). Handled ONCE here so
        // that no repository can forget it.
        if ($this->isConsoleContext()) {
            return $qb;
        }

        return $this->applyTenantScope($qb, $alias);
    }

    /**
     * Add this repository's access gate. Runs ONLY in an HTTP-request context —
     * console/worker/cron access is already granted before this is reached, so you
     * never handle it here, and you never call isConsoleContext() yourself.
     *
     * Abstract on purpose: every cross-tenant repository must state its scope. A
     * default implementation would silently leave a repository unfiltered on the web.
     *
     * Return $qb unchanged for "this role sees everything"; add a 1=0 clause to deny.
     * A null user id must deny explicitly — do NOT collapse it to `owner = NULL`,
     * which is never true in SQL and hides the intent.
     */
    abstract protected function applyTenantScope(QueryBuilder $qb, string $alias): QueryBuilder;

    /**
     * Add an always-on row filter — applied in EVERY context, console included.
     *
     * This is for content rules that are not about *who is asking*: soft-delete,
     * unpublished drafts, archived rows. They must survive the console bypass, so
     * they cannot live in {@see applyTenantScope()} — a CLI index build would
     * otherwise pick up rows the application considers deleted.
     *
     * Optional: the default applies no filter.
     */
    protected function applyContentFilter(QueryBuilder $qb, string $alias): QueryBuilder
    {
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
            $param = '_c_' . str_replace('.', '_', $field);
            if ($value === null) {
                $qb->andWhere("$alias.$field IS NULL");
            } elseif (is_array($value)) {
                if ($value === []) {
                    // Empty IN matches nothing — mirror Doctrine rather than emit broken SQL.
                    $qb->andWhere('1 = 0');
                } else {
                    $qb->andWhere("$alias.$field IN (:$param)")->setParameter($param, $value);
                }
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
