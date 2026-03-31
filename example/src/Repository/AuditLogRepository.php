<?php

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Mhpdigital\CrossTenantSecurity\Repository\AdminOnlyAccessRepository;

/**
 * ROLE_SUPER_ADMIN only — all other roles (and unauthenticated) see nothing.
 */
class AuditLogRepository extends ServiceEntityRepository
{
    use AdminOnlyAccessRepository;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }
}
