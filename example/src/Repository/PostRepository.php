<?php

namespace App\Repository;

use App\Entity\Post;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Mhpdigital\CrossTenantSecurity\Repository\CrossTenantRepository;

/**
 * Tenant-scoped: each user sees only their own posts.
 * ROLE_SUPER_ADMIN sees all posts.
 *
 * The base CrossTenantRepository blocks unauthenticated requests (1=0).
 * This override adds the per-user filter for authenticated non-super-admins.
 */
class PostRepository extends ServiceEntityRepository
{
    use CrossTenantRepository {
        CrossTenantRepository::createQueryBuilder as secureQueryBuilder;
    }

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    public function createQueryBuilder($alias, $indexBy = null): QueryBuilder
    {
        $qb = $this->secureQueryBuilder($alias, $indexBy);

        // Super admin sees everything; regular users see only their own posts.
        if ($this->getCurrentUser() !== null && $this->getHighestRole() !== 'ROLE_SUPER_ADMIN') {
            $qb->andWhere("$alias.author = :_post_author_id")
               ->setParameter('_post_author_id', $this->getUserId());
        }

        return $qb;
    }
}
