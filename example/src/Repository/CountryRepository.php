<?php

namespace App\Repository;

use App\Entity\Country;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Mhpdigital\CrossTenantSecurity\Repository\OpenAccessRepository;

/**
 * No filtering — a lookup table keyed on its ISO code rather than an "id" column.
 */
class CountryRepository extends ServiceEntityRepository
{
    use OpenAccessRepository;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Country::class);
    }
}
