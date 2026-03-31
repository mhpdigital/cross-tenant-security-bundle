<?php

namespace Mhpdigital\CrossTenantSecurity\Repository;

use Doctrine\ORM\QueryBuilder;

/**
 * For lookup/reference tables — all authenticated users can see all records.
 * Use for tags, statuses, categories, and other shared reference data.
 */
trait OpenAccessRepository
{
    use CrossTenantRepository;

    public function createQueryBuilder($alias, $indexBy = null): QueryBuilder
    {
        $em = $this->getEntityManager();

        return $em->createQueryBuilder()
            ->select($alias)
            ->from($em->getClassMetadata($this->getEntityName())->getName(), $alias, $indexBy);
    }
}
