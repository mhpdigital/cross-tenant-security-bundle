<?php

namespace Mhpdigital\CrossTenantSecurity\Request;

use Doctrine\ORM\EntityManagerInterface;
use Mhpdigital\CrossTenantSecurity\Repository\CrossTenantRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[AutoconfigureTag('controller.argument_value_resolver', ['priority' => 115])]
class CrossTenantEntityValueResolver implements ValueResolverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $class = $argument->getType();

        if ($class === null || !class_exists($class)) {
            return [];
        }

        try {
            $this->em->getClassMetadata($class);
        } catch (\Exception) {
            return [];
        }

        $repo = $this->em->getRepository($class);

        if (!$this->usesCrossTenantTrait($repo)) {
            return [];
        }

        $id = $request->attributes->get('id');

        if ($id === null) {
            return [];
        }

        $entity = $repo->createQueryBuilder('e')
            ->andWhere('e.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if ($entity === null) {
            throw new NotFoundHttpException();
        }

        return [$entity];
    }

    private function usesCrossTenantTrait(object $repo): bool
    {
        $traits = [];
        $class = get_class($repo);
        do {
            $traits += class_uses($class) ?: [];
        } while ($class = get_parent_class($class));

        return isset($traits[CrossTenantRepository::class]);
    }
}
