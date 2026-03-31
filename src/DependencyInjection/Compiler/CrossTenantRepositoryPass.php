<?php

namespace Mhpdigital\CrossTenantSecurity\DependencyInjection\Compiler;

use Mhpdigital\CrossTenantSecurity\Repository\CrossTenantRepository;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class CrossTenantRepositoryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();

            try {
                if ($class === null || !class_exists($class)) {
                    continue;
                }
            } catch (\Throwable) {
                // class_exists() can trigger autoloading that fails when a class
                // file exists but its dependencies are not installed (e.g. optional
                // Symfony components). Treat as "class not available" and skip.
                continue;
            }

            if (!$this->usesCrossTenantTrait($class)) {
                continue;
            }

            $entityClass = $this->resolveEntityClass($class);

            if (!class_exists($entityClass)) {
                continue;
            }

            $definition->setFactory([new Reference('doctrine.orm.entity_manager'), 'getRepository']);
            $definition->setArguments([$entityClass]);
        }
    }

    private function usesCrossTenantTrait(string $class): bool
    {
        $traits = [];
        $c = $class;
        do {
            $traits += class_uses($c) ?: [];
        } while ($c = get_parent_class($c));

        return isset($traits[CrossTenantRepository::class]);
    }

    /**
     * Resolves the entity class from a repository class using namespace convention.
     *
     * Converts e.g.:
     *   App\Repository\FooRepository        → App\Entity\Foo
     *   My\Deep\Repository\FooRepository    → My\Deep\Entity\Foo
     *   Acme\Bundle\Repository\FooRepository → Acme\Bundle\Entity\Foo
     *
     * Override this method in a subclass if your project uses a different namespace structure.
     */
    protected function resolveEntityClass(string $repositoryClass): string
    {
        $parts = explode('\\', $repositoryClass);
        $repoIndex = array_search('Repository', $parts, true);

        if ($repoIndex !== false) {
            $parts[$repoIndex] = 'Entity';
            $last = count($parts) - 1;
            if (str_ends_with($parts[$last], 'Repository')) {
                $parts[$last] = substr($parts[$last], 0, -10);
            }
            return implode('\\', $parts);
        }

        // Fallback: strip trailing "Repository" suffix
        return str_ends_with($repositoryClass, 'Repository')
            ? substr($repositoryClass, 0, -10)
            : $repositoryClass;
    }
}
