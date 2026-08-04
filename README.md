# Cross-Tenant Security Bundle

Role-based, row-level tenant isolation for Doctrine repositories in Symfony.

Pick one of three traits per repository to declare its access policy. Filtering is applied
automatically in `createQueryBuilder()` (and `find()`, `findBy()`, `findOneBy()`, `findAll()`),
so controllers and services never pass the current user around — the security context is read
from the token.

## Install & register

```bash
composer require mhpdigital/cross-tenant-security-bundle
```

Wire the factory as Doctrine's repository factory:

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        repository_factory: 'Mhpdigital\CrossTenantSecurity\Repository\CrossTenantRepositoryFactory'
```

The factory injects `token_storage`, `role_hierarchy` and `request_stack` into every repository
that uses one of the traits.

## Access matrix

| Trait | console / worker | authenticated web | token-less web |
|-------|------------------|-------------------|----------------|
| `CrossTenantRepository` | **all rows** | your tenant filter (override) | **none** (`1=0`) |
| `AdminOnlyAccessRepository` | **all rows** | `ROLE_SUPER_ADMIN` → all, else none | **none** |
| `OpenAccessRepository` | all rows | all rows | **all rows** — no repository-level gate |

"console / worker" = any process with no HTTP request in flight (a console command, a
Messenger/queue worker, a cron run). These are trusted local processes and get full access
automatically — see [Console context](#console--worker-context).

### What the matrix does and does not say

It describes the **repository** layer only: which rows a query returns in a given context. It
says nothing about *who can reach the code that runs the query* — that is your firewall and
`access_control`, which this bundle does not touch. The practical difference between the traits
is therefore where the backstop sits:

- `CrossTenantRepository` and `AdminOnlyAccessRepository` filter in the repository, so a route
  you accidentally leave unprotected still returns nothing to a token-less request.
- `OpenAccessRepository` applies no filter at all, so **the route's own protection is the only
  gate**. Behind a login-required route it means "all rows to every logged-in user"; on a route
  reachable without authentication it means "all rows to anyone".

So "token-less web → all rows" means *this trait will not stop such a request* — not that the
data is necessarily exposed. Choose `OpenAccessRepository` when you are content for the
repository to add no protection of its own.

## Examples

### 1. Tenant-scoped — `CrossTenantRepository`

Each user sees only their own rows; `ROLE_SUPER_ADMIN` sees all. Use the **trait-alias pattern**:
call the library's secured builder first (you inherit the unauthenticated `1=0` *and* the
console-context bypass), then add your own filter.

```php
use Mhpdigital\CrossTenantSecurity\Repository\CrossTenantRepository;

class PostRepository extends ServiceEntityRepository
{
    use CrossTenantRepository {
        CrossTenantRepository::createQueryBuilder as secureQueryBuilder;
    }

    public function createQueryBuilder($alias, $indexBy = null): QueryBuilder
    {
        $qb = $this->secureQueryBuilder($alias, $indexBy);

        // Authenticated non-super-admins are scoped to their own rows.
        // (No current user ⇒ console context ⇒ full access, so this is skipped.)
        if ($this->getCurrentUser() !== null && $this->getHighestRole() !== 'ROLE_SUPER_ADMIN') {
            $qb->andWhere("$alias.author = :_author")
               ->setParameter('_author', $this->getUserId());
        }

        return $qb;
    }
}
```

### 2. Unfiltered lookup — `OpenAccessRepository`

Reference data the repository does not gate at all: **every request that reaches it sees all
rows**, authenticated or not. Typical for `sex`, `country`, `currency`, `status`, `category`,
`tag`. No override needed.

```php
use Mhpdigital\CrossTenantSecurity\Repository\OpenAccessRepository;

class SexRepository extends ServiceEntityRepository
{
    use OpenAccessRepository;
}
```

> Because the trait adds no filter, whether this data is truly *public* is decided entirely by
> the routes that expose it. If the lookup must require login **regardless of how its routes are
> configured**, do not use this trait — use `CrossTenantRepository` (authenticated → all rows,
> token-less web → none), which enforces that at the repository.

### 3. Admin-only — `AdminOnlyAccessRepository`

Only `ROLE_SUPER_ADMIN` (and trusted console/worker runs) see rows; everyone else sees none.

```php
use Mhpdigital\CrossTenantSecurity\Repository\AdminOnlyAccessRepository;

class AuditLogRepository extends ServiceEntityRepository
{
    use AdminOnlyAccessRepository;
}
```

### 4. Content filter + access gate (custom `createQueryBuilder`)

When a repository has an **always-on content filter** (e.g. hide soft-deleted or unpublished
rows) *as well as* an access gate, reimplement `createQueryBuilder()` and place
`isConsoleContext()` **between** them — the content filter must apply in every context, but the
access gate is bypassed for console/worker:

```php
public function createQueryBuilder($alias, $indexBy = null): QueryBuilder
{
    $qb = $em->createQueryBuilder()->select($alias)->from(/* … */);

    $qb->andWhere("$alias.deleted IS NULL");   // (1) content filter — ALWAYS applies

    if ($this->isConsoleContext()) {           // CLI/worker bypasses ONLY the gate below
        return $qb;
    }

    // (2) access gate — bypassed in console
    if (!\in_array($this->getHighestRole(), self::READER_ROLES, true)) {
        $qb->andWhere('1=0');
    }

    return $qb;
}
```

A console index build then sees all **non-deleted** rows without
`createUnrestrictedQueryBuilder()`, while deleted rows stay hidden everywhere.

## Console / worker context

A console command, Messenger/queue worker or cron run has **no HTTP request** and carries no
security token. The bundle detects this (`isConsoleContext()` — request-presence, so functional
tests that issue a sub-request keep full web semantics) and grants **full access** through the
secured `createQueryBuilder()`. CLI/background code no longer needs
`createUnrestrictedQueryBuilder()`.

If `request_stack` is not wired, the bundle assumes a web context and fails **closed**.

## Explicit, context-independent bypass

```php
$qb = $repo->createUnrestrictedQueryBuilder('e'); // raw Doctrine builder, no filtering at all
```

Use only when you must bypass filtering regardless of context (e.g. an authenticated admin
maintenance action). For ordinary CLI/background work the console-context detection already
grants full access, so you should rarely need this.

## See also

The `example/` app is a runnable Symfony project exercising every trait —
`example/src/Repository/*` and `example/tests/Integration/BundleIntegrationTest.php` are the
executable documentation, including the console-context tests.
