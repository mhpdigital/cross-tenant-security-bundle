<?php

namespace App\Tests\Integration;

use App\Entity\AuditLog;
use App\Entity\Post;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\PostRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class BundleIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TokenStorageInterface $tokenStorage;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->em           = $container->get('doctrine.orm.entity_manager');
        $this->tokenStorage = $container->get('security.token_storage');

        $this->recreateSchema();
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

    private function recreateSchema(): void
    {
        $metadata   = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    private function loginAs(User $user): void
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $this->tokenStorage->setToken($token);
    }

    private function logout(): void
    {
        $this->tokenStorage->setToken(null);
    }

    private function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();
    }

    private function repo(string $class): object
    {
        return $this->em->getRepository($class);
    }

    // -------------------------------------------------------------------------
    // Container / wiring
    // -------------------------------------------------------------------------

    public function testContainerCompiles(): void
    {
        // If we get here, the kernel booted without the compiler pass throwing
        $this->assertTrue(true);
    }

    public function testRepositoryFactoryInjectsSecurityDependencies(): void
    {
        $repo = $this->repo(Post::class);
        $this->assertInstanceOf(PostRepository::class, $repo);
        // Factory must have called setTokenStorage — getTokenStorage() should not throw
        $this->assertNotNull($repo->getTokenStorage());
    }

    // -------------------------------------------------------------------------
    // PostRepository — CrossTenantRepository with owner filter
    // -------------------------------------------------------------------------

    public function testUnauthenticatedUserSeesNoPosts(): void
    {
        $user = new User('alice@example.com');
        $this->persist($user, new Post('Alice post 1', $user), new Post('Alice post 2', $user));

        $this->logout();

        $posts = $this->repo(Post::class)->findAll();
        $this->assertCount(0, $posts);
    }

    public function testAuthenticatedUserSeesOnlyOwnPosts(): void
    {
        $alice = new User('alice@example.com');
        $bob   = new User('bob@example.com');
        $this->persist(
            $alice, $bob,
            new Post('Alice post 1', $alice),
            new Post('Alice post 2', $alice),
            new Post('Bob post 1', $bob),
        );

        $this->loginAs($alice);

        $posts = $this->repo(Post::class)->findAll();
        $this->assertCount(2, $posts);
        foreach ($posts as $post) {
            $this->assertSame('alice@example.com', $post->getAuthor()->getEmail());
        }
    }

    public function testAuthenticatedUserDoesNotSeeOtherUsersPosts(): void
    {
        $alice = new User('alice@example.com');
        $bob   = new User('bob@example.com');
        $this->persist(
            $alice, $bob,
            new Post('Alice post', $alice),
            new Post('Bob post', $bob),
        );

        $this->loginAs($bob);

        $posts = $this->repo(Post::class)->findAll();
        $this->assertCount(1, $posts);
        $this->assertSame('Bob post', $posts[0]->getTitle());
    }

    public function testSuperAdminSeesAllPosts(): void
    {
        $alice = new User('alice@example.com');
        $bob   = new User('bob@example.com');
        $admin = new User('admin@example.com', ['ROLE_SUPER_ADMIN']);
        $this->persist(
            $alice, $bob, $admin,
            new Post('Alice post', $alice),
            new Post('Bob post', $bob),
        );

        $this->loginAs($admin);

        $posts = $this->repo(Post::class)->findAll();
        $this->assertCount(2, $posts);
    }

    public function testUnrestrictedQueryBuilderIgnoresSecurityContext(): void
    {
        $alice = new User('alice@example.com');
        $bob   = new User('bob@example.com');
        $this->persist(
            $alice, $bob,
            new Post('Alice post', $alice),
            new Post('Bob post', $bob),
        );

        $this->loginAs($alice);

        /** @var PostRepository $repo */
        $repo  = $this->repo(Post::class);
        $posts = $repo->createUnrestrictedQueryBuilder('p')->getQuery()->getResult();
        $this->assertCount(2, $posts);
    }

    // -------------------------------------------------------------------------
    // TagRepository — OpenAccessRepository
    // -------------------------------------------------------------------------

    public function testUnauthenticatedUserSeesAllTags(): void
    {
        $this->persist(new Tag('php'), new Tag('symfony'), new Tag('doctrine'));
        $this->logout();

        $tags = $this->repo(Tag::class)->findAll();
        $this->assertCount(3, $tags);
    }

    public function testAuthenticatedUserSeesAllTags(): void
    {
        $user = new User('alice@example.com');
        $this->persist($user, new Tag('php'), new Tag('symfony'));
        $this->loginAs($user);

        $tags = $this->repo(Tag::class)->findAll();
        $this->assertCount(2, $tags);
    }

    // -------------------------------------------------------------------------
    // AuditLogRepository — AdminOnlyAccessRepository
    // -------------------------------------------------------------------------

    public function testUnauthenticatedUserSeesNoAuditLogs(): void
    {
        $this->persist(new AuditLog('user.login'), new AuditLog('user.logout'));
        $this->logout();

        $logs = $this->repo(AuditLog::class)->findAll();
        $this->assertCount(0, $logs);
    }

    public function testRoleUserSeesNoAuditLogs(): void
    {
        $user = new User('alice@example.com');
        $this->persist($user, new AuditLog('user.login'));
        $this->loginAs($user);

        $logs = $this->repo(AuditLog::class)->findAll();
        $this->assertCount(0, $logs);
    }

    public function testRoleAdminSeesNoAuditLogs(): void
    {
        $user = new User('admin@example.com', ['ROLE_ADMIN']);
        $this->persist($user, new AuditLog('user.login'));
        $this->loginAs($user);

        $logs = $this->repo(AuditLog::class)->findAll();
        $this->assertCount(0, $logs);
    }

    public function testSuperAdminSeesAllAuditLogs(): void
    {
        $admin = new User('admin@example.com', ['ROLE_SUPER_ADMIN']);
        $this->persist(
            $admin,
            new AuditLog('user.login'),
            new AuditLog('user.created'),
            new AuditLog('payment.processed'),
        );

        $this->loginAs($admin);

        $logs = $this->repo(AuditLog::class)->findAll();
        $this->assertCount(3, $logs);
    }

    // -------------------------------------------------------------------------
    // find() — must respect security filters (was previously unprotected)
    // -------------------------------------------------------------------------

    public function testFindByIdReturnsNullForUnauthenticatedUser(): void
    {
        $user = new User('alice@example.com');
        $post = new Post('Secret post', $user);
        $this->persist($user, $post);

        $this->logout();

        $found = $this->repo(Post::class)->find($post->getId());
        $this->assertNull($found, 'Unauthenticated user must not be able to find() a post by ID');
    }

    public function testFindByIdReturnsNullForOtherUsersPost(): void
    {
        $alice = new User('alice@example.com');
        $bob   = new User('bob@example.com');
        $post  = new Post('Alice secret', $alice);
        $this->persist($alice, $bob, $post);

        $this->loginAs($bob);

        $found = $this->repo(Post::class)->find($post->getId());
        $this->assertNull($found, 'User must not be able to find() another user\'s post by ID');
    }

    public function testFindByIdReturnsOwnPost(): void
    {
        $alice = new User('alice@example.com');
        $post  = new Post('My post', $alice);
        $this->persist($alice, $post);

        $this->loginAs($alice);

        $found = $this->repo(Post::class)->find($post->getId());
        $this->assertNotNull($found);
        $this->assertSame('My post', $found->getTitle());
    }

    public function testSuperAdminCanFindAnyPostById(): void
    {
        $alice = new User('alice@example.com');
        $admin = new User('admin@example.com', ['ROLE_SUPER_ADMIN']);
        $post  = new Post('Alice post', $alice);
        $this->persist($alice, $admin, $post);

        $this->loginAs($admin);

        $found = $this->repo(Post::class)->find($post->getId());
        $this->assertNotNull($found);
        $this->assertSame('Alice post', $found->getTitle());
    }

    public function testFindByIdRespectsAdminOnlyAccess(): void
    {
        $user = new User('alice@example.com');
        $log  = new AuditLog('secret.action');
        $this->persist($user, $log);

        $this->loginAs($user);

        $found = $this->repo(AuditLog::class)->find($log->getId());
        $this->assertNull($found, 'Regular user must not be able to find() an audit log by ID');
    }

    public function testSuperAdminCanFindAuditLogById(): void
    {
        $admin = new User('admin@example.com', ['ROLE_SUPER_ADMIN']);
        $log   = new AuditLog('system.event');
        $this->persist($admin, $log);

        $this->loginAs($admin);

        $found = $this->repo(AuditLog::class)->find($log->getId());
        $this->assertNotNull($found);
        $this->assertSame('system.event', $found->getAction());
    }

    public function testFindByIdRespectsOpenAccess(): void
    {
        $tag = new Tag('php');
        $this->persist($tag);

        $this->logout();

        $found = $this->repo(Tag::class)->find($tag->getId());
        $this->assertNotNull($found, 'Open-access entity must be findable by ID even when unauthenticated');
        $this->assertSame('php', $found->getName());
    }

    // -------------------------------------------------------------------------
    // Role hierarchy
    // -------------------------------------------------------------------------

    public function testRoleHierarchyIsRespectedBySuperAdmin(): void
    {
        // ROLE_SUPER_ADMIN inherits ROLE_ADMIN which inherits ROLE_USER
        // So a super admin should see their own posts AND all audit logs
        $admin = new User('admin@example.com', ['ROLE_SUPER_ADMIN']);
        $user  = new User('user@example.com');
        $this->persist(
            $admin, $user,
            new Post('Admin post', $admin),
            new Post('User post', $user),
            new AuditLog('system.event'),
        );

        $this->loginAs($admin);

        // Super admin sees all posts (cross-tenant)
        $this->assertCount(2, $this->repo(Post::class)->findAll());
        // Super admin sees audit logs
        $this->assertCount(1, $this->repo(AuditLog::class)->findAll());
        // Super admin sees all tags (open access, same as everyone)
        $this->persist(new Tag('php'));
        $this->assertCount(1, $this->repo(Tag::class)->findAll());
    }
}
