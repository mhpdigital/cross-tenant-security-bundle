# Changelog

## 1.1.1 — unreleased

### Fixed
- **`findBy()` now honours array criteria as `IN (...)`.** Passing an array value
  (e.g. `findBy(['id' => [1, 2, 3]])`) previously rendered as `field = 1, 2, 3` and threw a
  fatal SQL syntax error (500) — a call shape that is valid on stock Doctrine's
  `ObjectRepository::findBy()`. An empty array now matches nothing (`1 = 0`) instead of
  emitting broken SQL, mirroring Doctrine. `null` still renders as `IS NULL` and scalars as
  `= :param`. `findOneBy()` is fixed automatically as it delegates to `findBy()`. Security
  filters are still ANDed with the `IN`, so array criteria cannot leak cross-tenant rows.

## 1.1.0 — unreleased

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
