# Changelog

## Unreleased

### Planned
- **Make `AdminOnlyAccessRepository` check the role hierarchy rather than an exact string.**
  It currently gates on `getHighestRole() !== 'ROLE_SUPER_ADMIN'`, comparing against the single
  highest-scoring role. A user who also holds a custom role that reaches more roles than
  `ROLE_SUPER_ADMIN` would have that role selected as "highest" and be denied despite being a
  super admin. This fails **closed**, so it is not a data leak — but it is surprising, and the
  fix is to test whether the token's reachable roles contain the admin role instead of matching
  one string. Likely paired with making the admin role name configurable rather than hard-coded.

## 1.1.3 — 2026-08-04

### Documentation
- **Clarified what the access matrix actually claims for `OpenAccessRepository`.** The
  "token-less web" cell read **all rows (public)**, which asserted something the bundle does
  not control. The trait's `createQueryBuilder()` never reads the token and emits no `WHERE` —
  that is a statement about *filtering*, not about *exposure*. Whether the data is reachable
  without logging in is decided by the firewall and `access_control` on the routes that expose
  it, and this bundle has no route layer.
- Documented the asymmetry that makes the choice of trait matter: `CrossTenantRepository` and
  `AdminOnlyAccessRepository` apply `1=0` in the repository, so a route accidentally left
  unprotected still returns nothing; `OpenAccessRepository` has **no repository-level
  backstop**, so the route's own protection is the only gate. Use `CrossTenantRepository` when
  login must be enforced regardless of how the routes are configured.
- The `OpenAccessRepository` docblock was rewritten to match the README, so the two cannot
  drift apart.

No behaviour change — documentation and comments only. All 43 example integration tests pass
unchanged.

## 1.1.2 — 2026-07-11

### Fixed
- **The repository factory now builds both repository constructor shapes.** The factory
  hard-coded the `ServiceEntityRepository(ManagerRegistry, entityClass)` signature used by
  MakerBundle-generated custom repos, so any `#[ORM\Entity]` with **no** `repositoryClass`
  (which gets Doctrine's default `EntityRepository(EntityManagerInterface, ClassMetadata)`)
  threw `TypeError: EntityRepository::__construct(): Argument #1 ($em) must be of type
  EntityManagerInterface, …Registry given`. This regressed in be5a121, which flipped an
  earlier version that supported only repo-less entities — the factory only ever supported
  one shape at a time. It now detects `is_a($class, ServiceEntityRepository::class)` and
  constructs whichever the repository class needs, so `getRepository()` and `#[MapEntity]`
  (find-by-PK) work for both. No security impact: `#[MapEntity]` resolves via `find()`,
  which is by primary key and never went through `createQueryBuilder()` role-filtering.

## 1.1.1 — 2026-07-07

### Fixed
- **`findBy()` now honours array criteria as `IN (...)`.** Passing an array value
  (e.g. `findBy(['id' => [1, 2, 3]])`) previously rendered as `field = 1, 2, 3` and threw a
  fatal SQL syntax error (500) — a call shape that is valid on stock Doctrine's
  `ObjectRepository::findBy()`. An empty array now matches nothing (`1 = 0`) instead of
  emitting broken SQL, mirroring Doctrine. `null` still renders as `IS NULL` and scalars as
  `= :param`. `findOneBy()` is fixed automatically as it delegates to `findBy()`. Security
  filters are still ANDed with the `IN`, so array criteria cannot leak cross-tenant rows.

## 1.1.0 — 2026-06-05

### Added
- **Console-context auto-detection.** Repositories using `CrossTenantRepository` or
  `AdminOnlyAccessRepository` now grant **full access** automatically when there is no
  HTTP request in flight — i.e. console commands, Messenger/queue workers and cron runs.
  These trusted local processes carry no security token and were previously filtered down
  to nothing, forcing callers to use `createUnrestrictedQueryBuilder()`. That call is still
  available for an explicit, context-independent bypass, but is no longer required for
  CLI/background work.
- New `protected isConsoleContext(): bool` on the `CrossTenantRepository` trait, so custom
  `createQueryBuilder()` overrides can apply the same console bypass before their own role
  logic.
- The repository factory now injects `request_stack` (wired in the bundle's
  `config/services.yaml`).

### Behaviour change (on upgrade)
- A token-less **CLI/worker** context now sees all rows through `createQueryBuilder()`
  (previously 0). Token-less **web** requests are unchanged — still fail-closed (`1=0`).
- The discriminator is **request-presence**, not `php_sapi`: functional tests that issue a
  sub-request keep full web-security semantics. If `request_stack` is not injected (older
  wiring), behaviour falls back to "assume web" so it fails closed, never open.
- Repositories that **override** `createQueryBuilder()` keep their existing behaviour until
  they opt in by calling `isConsoleContext()`.

Earlier history: see git tags (v1.0.3 routed `find()` through the secured builder; v1.0.0–v1.0.2 initial releases).
