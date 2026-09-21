# Cross-Tenant Security Bundle

Role-based, row-level tenant isolation for Doctrine repositories in Symfony.

Pick one of three traits per repository to declare its access policy. Filtering is applied
automatically in `createQueryBuilder()` (and `find()`, `findBy()`, `findOneBy()`, `findAll()`),
so controllers and services never pass the current user around — the security context is read
from the token.

`createQueryBuilder()` is **final**: it owns the base builder and the console/worker bypass, and
calls into two hooks your repository implements. You never re-implement it, so the bypass cannot
be forgotten. See [Writing a repository](#writing-a-repository).

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
| `CrossTenantRepository` | **all rows** | your `applyTenantScope()` hook | whatever your hook returns for role `''` (deny with `1=0`) |
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

## Writing a repository

`createQueryBuilder()` is final and runs this sequence:

```
parent::createQueryBuilder()      the plain Doctrine builder
  → applyContentFilter()          optional. EVERY context, console included
  → if (isConsoleContext()) return   trusted local process — gate skipped
  → applyTenantScope()            required. Web only. This is the access gate
```

Two hooks, with a deliberate split:

| Hook | Required | Runs in console? | For |
|------|----------|------------------|-----|
| `applyTenantScope(QueryBuilder $qb, string $alias)` | **yes** (abstract) | no | who may see which rows |
| `applyContentFilter(QueryBuilder $qb, string $alias)` | no | **yes** | rules that hold regardless of caller — soft-delete, drafts |

`applyTenantScope()` is abstract on purpose: a repository that forgets to declare its scope
fails to compile rather than silently serving unfiltered rows on the web.

## Examples

### 1. Tenant-scoped — `CrossTenantRepository`

Each user sees only their own rows; `ROLE_SUPER_ADMIN` sees all. Implement the gate only — the
base builder and the console bypass are already applied before your hook is called.

```php
use Mhpdigital\CrossTenantSecurity\Repository\CrossTenantRepository;

class PostRepository extends ServiceEntityRepository
{
    use CrossTenantRepository;

    protected function applyTenantScope(QueryBuilder $qb, string $alias): QueryBuilder
    {
        // Unauthenticated web request — no rows.
        if ($this->getHighestRole() === '') {
            return $qb->andWhere('1=0');
        }

        // Authenticated non-super-admins are scoped to their own rows.
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

### 4. Content filter + access gate — both hooks

When a repository has an **always-on content filter** (e.g. hide soft-deleted or unpublished
rows) *as well as* an access gate, put them in different hooks. The bundle runs the content
filter before the console bypass and the gate after, so the content rule holds everywhere while
the gate is skipped for trusted local processes:

```php
// (1) ALWAYS applies — including console/worker/cron.
protected function applyContentFilter(QueryBuilder $qb, string $alias): QueryBuilder
{
    return $qb->andWhere("$alias.deleted IS NULL");
}

// (2) access gate — never reached in console context.
protected function applyTenantScope(QueryBuilder $qb, string $alias): QueryBuilder
{
    if (!\in_array($this->getHighestRole(), self::READER_ROLES, true)) {
        $qb->andWhere('1=0');
    }

    return $qb;
}
```

A CLI index build then sees all **non-deleted** rows without
`createUnrestrictedQueryBuilder()`, while deleted rows stay hidden everywhere. Putting the
soft-delete clause in `applyTenantScope()` instead would leak deleted rows into every console
job — that is the mistake the split exists to prevent.

## Entity arguments in controllers — `#[MapEntity]` and type-hints

Use Symfony's own entity resolution. There is nothing to register and nothing to avoid:

```php
#[Route('/posts/{id}')]
public function show(Post $post): Response { … }

#[Route('/courses/{slug}')]
public function course(#[MapEntity(mapping: ['slug' => 'slug'])] Course $course): Response { … }

#[Route('/posts/{id}/comments/{comment_id}')]
public function comment(Post $post, #[MapEntity(id: 'comment_id')] Comment $comment): Response { … }
```

Symfony's `EntityValueResolver` loads through `$repository->find()` and
`$repository->findOneBy()`. The trait overrides both to run through the secured
`createQueryBuilder()`, so every shape above is tenant-scoped, and a row the caller cannot see
is a **404** — the same response as a row that does not exist, so existence never leaks.
Nullable arguments resolve to `null`; `#[MapEntity(disabled: true)]` is honoured.

Two things stay your responsibility:

- **`#[MapEntity(expr: '…')]`** calls whichever repository method the expression names. It is
  scoped exactly when that method builds its query with `createQueryBuilder()` — which is true
  of `find()`, `findBy()`, `findOneBy()`, `findAll()` and of any custom method written the
  normal way — and unscoped if the method uses `createUnrestrictedQueryBuilder()`, raw DQL from
  the entity manager, or native SQL.
- **`find($id, $lockMode)` with a lock mode** goes to `EntityManager::find()` and is not
  filtered. Entity arguments never pass a lock mode; this only matters for your own calls.

`example/tests/Integration/EntityArgumentResolutionTest.php` runs each shape through the real
argument-resolver chain.

## Console / worker context

A console command, Messenger/queue worker or cron run has **no HTTP request** and carries no
security token. The bundle detects this (`isConsoleContext()` — request-presence, so functional
tests that issue a sub-request keep full web semantics) and grants **full access** through the
secured `createQueryBuilder()`. CLI/background code no longer needs
`createUnrestrictedQueryBuilder()`.

Since 2.0.0 this check lives in the final `createQueryBuilder()` rather than in each repository,
so it is applied uniformly and cannot be omitted. Do **not** re-test it inside
`applyTenantScope()` — that hook only ever runs in a web context.

Note the discriminator is request-presence, not `php_sapi_name()`. A repository that gates on
`php_sapi_name() === 'cli'` is unfiltered for the whole of a PHPUnit run, which hides real
access-control failures from your test suite.

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
