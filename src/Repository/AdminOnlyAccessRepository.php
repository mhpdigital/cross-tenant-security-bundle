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

    public function createQueryBuilder(string $alias, ?string $indexBy = null): QueryBuilder
    {
        $em = $this->getEntityManager();
        $qb = $em->createQueryBuilder()
            ->select($alias)
            ->from($em->getClassMetadata($this->getEntityName())->getName(), $alias, $indexBy);

        if (php_sapi_name() === 'cli') {
            return $qb;
        }

        if ($this->getHighestRole() !== 'ROLE_SUPER_ADMIN') {
            $qb->where('1=0');
        }

        return $qb;
    }
}
