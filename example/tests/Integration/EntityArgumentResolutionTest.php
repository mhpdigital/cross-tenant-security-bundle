<?php

namespace App\Tests\Integration;

use App\Entity\Country;
use App\Entity\Post;
use App\Entity\Tag;
use App\Entity\User;
use App\Tests\Fixtures\EntityArgumentController;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Entity controller arguments — `Post $post`, `#[MapEntity(...)] Post $post` — must be
 * tenant-scoped whatever the route is keyed on, and must 404 (never 403) on a row the
 * caller cannot see.
 *
 * These run the real argument_resolver chain, so they cover what a controller gets.
 * Symfony's EntityValueResolver loads through $repository->find() / findOneBy(), which
 * CrossTenantRepository overrides to go through createQueryBuilder() — that is where
 * the scoping comes from.
 */
class EntityArgumentResolutionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TokenStorageInterface $tokenStorage;
    private RequestStack $requestStack;
    private ArgumentResolverInterface $argumentResolver;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->em               = $container->get('doctrine.orm.entity_manager');
        $this->tokenStorage     = $container->get('security.token_storage');
        $this->requestStack     = $container->get('request_stack');
        $this->argumentResolver = $container->get('argument_resolver');

        $metadata   = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->tokenStorage->setToken(null);
    }

    protected function tearDown(): void
    {
        $this->tokenStorage->setToken(null);
        $this->em->close();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function loginAs(User $user): void
    {
        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * Resolve the arguments of EntityArgumentController::$action for a request carrying
     * $routeParams, with that request on the stack (web context).
     */
    private function resolve(string $action, array $routeParams): array
    {
        $request = new Request();
        $request->attributes->add($routeParams);

        while ($this->requestStack->getCurrentRequest() !== null) {
            $this->requestStack->pop();
        }
        $this->requestStack->push($request);

        return $this->argumentResolver->getArguments($request, [new EntityArgumentController(), $action]);
    }

    // -------------------------------------------------------------------------
    // {id} routes
    // -------------------------------------------------------------------------

    public function testIdRouteResolvesOwnPost(): void
    {
        $alice = new User('alice@example.com');
        $post  = new Post('Alice post', $alice);
        $this->persist($alice, $post);
        $this->loginAs($alice);

        [$resolved] = $this->resolve('byId', ['id' => $post->getId()]);

        $this->assertSame('Alice post', $resolved->getTitle());
    }

    public function testIdRouteIs404ForAnotherTenantsPost(): void
    {
        $alice = new User('alice@example.com');
        $bob   = new User('bob@example.com');
        $post  = new Post('Alice post', $alice);
        $this->persist($alice, $bob, $post);
        $this->loginAs($bob);

        $this->expectException(NotFoundHttpException::class);
        $this->resolve('byId', ['id' => $post->getId()]);
    }

    // -------------------------------------------------------------------------
    // Routes keyed on something other than {id}
    // -------------------------------------------------------------------------

    public function testMappedFieldRouteResolvesOwnPost(): void
    {
        $alice = new User('alice@example.com');
        $this->persist($alice, new Post('alice-post', $alice));
        $this->loginAs($alice);

        [$resolved] = $this->resolve('byMappedField', ['title' => 'alice-post']);

        $this->assertSame('alice-post', $resolved->getTitle());
    }

    public function testMappedFieldRouteIs404ForAnotherTenantsPost(): void
    {
        $alice = new User('alice@example.com');
        $bob   = new User('bob@example.com');
        $this->persist($alice, $bob, new Post('alice-post', $alice));
        $this->loginAs($bob);

        $this->expectException(NotFoundHttpException::class);
        $this->resolve('byMappedField', ['title' => 'alice-post']);
    }

    public function testNamedIdRouteResolvesOwnPost(): void
    {
        $alice = new User('alice@example.com');
        $post  = new Post('Alice post', $alice);
        $this->persist($alice, $post);
        $this->loginAs($alice);

        [$resolved] = $this->resolve('byNamedId', ['post_id' => $post->getId()]);

        $this->assertSame('Alice post', $resolved->getTitle());
    }

    public function testNamedIdRouteIs404ForAnotherTenantsPost(): void
    {
        $alice = new User('alice@example.com');
        $bob   = new User('bob@example.com');
        $post  = new Post('Alice post', $alice);
        $this->persist($alice, $bob, $post);
        $this->loginAs($bob);

        $this->expectException(NotFoundHttpException::class);
        $this->resolve('byNamedId', ['post_id' => $post->getId()]);
    }

    // -------------------------------------------------------------------------
    // Several entity arguments in one action
    // -------------------------------------------------------------------------

    public function testTwoEntityArgumentsResolveFromTheirOwnRouteAttributes(): void
    {
        $alice = new User('alice@example.com');
        $post  = new Post('Alice post', $alice);
        // Pad the tag table so the wanted tag's id cannot coincide with the post's id.
        $this->persist($alice, $post, new Tag('padding-1'), new Tag('padding-2'));
        $wanted = new Tag('wanted');
        $this->persist($wanted);
        $this->assertNotSame($post->getId(), $wanted->getId());
        $this->loginAs($alice);

        [$resolvedPost, $resolvedTag] = $this->resolve('twoEntities', [
            'id'     => $post->getId(),
            'tag_id' => $wanted->getId(),
        ]);

        $this->assertSame('Alice post', $resolvedPost->getTitle());
        $this->assertSame('wanted', $resolvedTag->getName(), 'The tag must come from {tag_id}, not from {id}.');
    }

    public function testTwoPlainEntityArgumentsResolveFromTheirOwnRouteAttributes(): void
    {
        $alice = new User('alice@example.com');
        $post  = new Post('Alice post', $alice);
        $this->persist($alice, $post, new Tag('padding-1'), new Tag('padding-2'));
        $wanted = new Tag('wanted');
        $this->persist($wanted);
        $this->assertNotSame($post->getId(), $wanted->getId());
        $this->loginAs($alice);

        [$resolvedPost, $resolvedTag] = $this->resolve('twoEntitiesByName', [
            'id'  => $post->getId(),
            'tag' => $wanted->getId(),
        ]);

        $this->assertSame('Alice post', $resolvedPost->getTitle());
        $this->assertSame('wanted', $resolvedTag->getName(), 'The tag must come from {tag}, not from {id}.');
    }

    // -------------------------------------------------------------------------
    // Primary key not called "id"
    // -------------------------------------------------------------------------

    public function testFindResolvesAPrimaryKeyThatIsNotCalledId(): void
    {
        $this->persist(new Country('NZ', 'New Zealand'), new Country('AU', 'Australia'));

        $found = $this->em->getRepository(Country::class)->find('NZ');

        $this->assertNotNull($found);
        $this->assertSame('New Zealand', $found->getName());
    }

    public function testNaturalKeyRouteResolves(): void
    {
        $this->persist(new Country('NZ', 'New Zealand'), new Country('AU', 'Australia'));

        [$resolved] = $this->resolve('byNaturalKey', ['code' => 'AU']);

        $this->assertSame('Australia', $resolved->getName());
    }

    // -------------------------------------------------------------------------
    // Nullable / disabled
    // -------------------------------------------------------------------------

    public function testNullableArgumentResolvesToNullInsteadOf404(): void
    {
        $alice = new User('alice@example.com');
        $bob   = new User('bob@example.com');
        $post  = new Post('Alice post', $alice);
        $this->persist($alice, $bob, $post);
        $this->loginAs($bob);

        [$resolved] = $this->resolve('nullable', ['id' => $post->getId()]);

        $this->assertNull($resolved);
    }

    public function testDisabledMapEntityIsNotResolved(): void
    {
        $alice = new User('alice@example.com');
        $post  = new Post('Alice post', $alice);
        $this->persist($alice, $post);
        $this->loginAs($alice);

        [$resolved] = $this->resolve('disabled', ['id' => $post->getId()]);

        $this->assertNull($resolved, '#[MapEntity(disabled: true)] must leave the argument to its default.');
    }

}
