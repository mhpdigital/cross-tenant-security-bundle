<?php

namespace Mhpdigital\CrossTenantSecurity\Tests\Fixtures\Repository;

use Doctrine\ORM\EntityRepository;
use Mhpdigital\CrossTenantSecurity\Repository\AdminOnlyAccessRepository;

class AdminOnlyFixtureRepository extends EntityRepository
{
    use AdminOnlyAccessRepository;
}
