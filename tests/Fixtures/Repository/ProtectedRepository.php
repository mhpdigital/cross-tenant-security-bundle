<?php

namespace Mhpdigital\CrossTenantSecurity\Tests\Fixtures\Repository;

use Doctrine\ORM\EntityRepository;
use Mhpdigital\CrossTenantSecurity\Repository\CrossTenantRepository;

class ProtectedRepository extends EntityRepository
{
    use CrossTenantRepository;
}
