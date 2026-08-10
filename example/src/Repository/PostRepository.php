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
 * Only the access gate lives here. The base builder and the console/worker bypass
 * are owned by CrossTenantRepository::createQueryBuilder(), which is final.
 */
class PostRepository extends ServiceEntityRepository
{
    use CrossTenantRepository;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    protected function applyTenantScope(QueryBuilder $qb, string $alias): QueryBuilder
    {
        // Unauthenticated web request — no rows.
        if ($this->getHighestRole() === '') {
            return $qb->andWhere('1=0');
        }

        // Super admin sees everything; regular users see only their own posts.
        if ($this->getCurrentUser() !== null && $this->getHighestRole() !== 'ROLE_SUPER_ADMIN') {
            $qb->andWhere("$alias.author = :_post_author_id")
               ->setParameter('_post_author_id', $this->getUserId());
        }

        return $qb;
    }
}
