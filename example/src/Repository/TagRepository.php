<?php

namespace App\Repository;

use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Mhpdigital\CrossTenantSecurity\Repository\OpenAccessRepository;

/**
 * No filtering — all users (including unauthenticated) see all tags.
 */
class TagRepository extends ServiceEntityRepository
{
    use OpenAccessRepository;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }
}
