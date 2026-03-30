<?php

namespace Mhpdigital\CrossTenantSecurity;

use Mhpdigital\CrossTenantSecurity\DependencyInjection\Compiler\CrossTenantRepositoryPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class CrossTenantSecurityBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new CrossTenantRepositoryPass());
    }
}
