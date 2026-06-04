<?php

namespace Mhpdigital\CrossTenantSecurity\Repository;

use Doctrine\ORM\QueryBuilder;

/**
 * For genuinely PUBLIC reference/lookup tables — every request sees all rows,
 * INCLUDING unauthenticated ones (no login required). Use only for data that is
 * safe to expose publicly, e.g. sex, country, currency, status, category, tag.
 *
 * If a lookup must instead require login, do NOT use this trait — use
 * CrossTenantRepository (authenticated users see all rows via createQueryBuilder();
 * a token-less web request sees none).
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
