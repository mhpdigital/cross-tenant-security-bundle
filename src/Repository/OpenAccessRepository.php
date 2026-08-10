<?php

namespace Mhpdigital\CrossTenantSecurity\Repository;

use Doctrine\ORM\QueryBuilder;

/**
 * For reference/lookup tables this bundle does NOT gate: every request that reaches
 * the repository sees all rows, authenticated or not. Typical for sex, country,
 * currency, status, category, tag.
 *
 * This trait adds no protection of its own, so whether the data is actually public
 * is decided by the routes that expose it — a login-required route makes it
 * "all rows to every logged-in user"; an unauthenticated route makes it "all rows to
 * anyone". Unlike the other traits there is no repository-level backstop if a route
 * is left open by mistake.
 *
 * If a lookup must require login regardless of how its routes are configured, do NOT
 * use this trait — use CrossTenantRepository (authenticated users see all rows via
 * createQueryBuilder(); a token-less web request sees none).
 */
trait OpenAccessRepository
{
    use CrossTenantRepository;

    /**
     * No gate: every request that reaches this repository sees all rows. The token is
     * never read, so authenticated and unauthenticated callers are treated alike.
     */
    protected function applyTenantScope(QueryBuilder $qb, string $alias): QueryBuilder
    {
        return $qb;
    }
}
