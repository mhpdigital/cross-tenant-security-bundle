<?php

namespace Mhpdigital\CrossTenantSecurity\Repository;

use Doctrine\ORM\QueryBuilder;

/**
 * For tables that only ROLE_SUPER_ADMIN can access.
 * All other roles (and unauthenticated requests) receive an empty result set.
 */
trait AdminOnlyAccessRepository
{
    use CrossTenantRepository;

    /**
     * Only ROLE_SUPER_ADMIN sees rows on the web; everyone else gets an empty set.
     *
     * Console / worker / cron never reaches this — CrossTenantRepository grants those
     * full access first, so a CLI job over admin-only tables does not come back empty.
     */
    protected function applyTenantScope(QueryBuilder $qb, string $alias): QueryBuilder
    {
        if ($this->getHighestRole() !== 'ROLE_SUPER_ADMIN') {
            $qb->andWhere('1=0');
        }

        return $qb;
    }
}
