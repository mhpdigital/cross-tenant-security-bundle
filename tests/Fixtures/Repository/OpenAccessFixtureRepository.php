<?php

namespace Mhpdigital\CrossTenantSecurity\Tests\Fixtures\Repository;

use Doctrine\ORM\EntityRepository;
use Mhpdigital\CrossTenantSecurity\Repository\OpenAccessRepository;

class OpenAccessFixtureRepository extends EntityRepository
{
    use OpenAccessRepository;
}
