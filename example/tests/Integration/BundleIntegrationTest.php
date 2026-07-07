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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class BundleIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TokenStorageInterface $tokenStorage;
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->em           = $container->get('doctrine.orm.entity_manager');
        $this->tokenStorage = $container->get('security.token_storage');
        $this->requestStack = $container->get('request_stack');

        $this->recreateSchema();
        $this->tokenStorage->setToken(null);

        // Default every test to a *web* context (a Request on the stack) so the security
        // filtering behaves as it does for an HTTP request. Console-context tests opt out
        // explicitly via enterConsoleContext().
        $this->enterWebContext();
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

    /** Simulate an HTTP request being handled (a Request on the stack). */
    private function enterWebContext(): void
    {
        if ($this->requestStack->getCurrentRequest() === null) {
            $this->requestStack->push(new Request());
        }
    }

    /** Simulate a console command / queue worker / cron run (no Request on the stack). */
    private function enterConsoleContext(): void
    {
        while ($this->requestStack->getCurrentRequest() !== null) {
            $this->requestStack->pop();
        }
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

    // -------------------------------------------------------------------------
    // Console / worker context — no HTTP request ⇒ trusted local process ⇒ full access
    // -------------------------------------------------------------------------

    public function testConsoleContextSeesAllPostsWithoutToken(): void
    {
        $alice = new User('alice@example.com');
        $bob   = new User('bob@example.com');
        $this->persist($alice, $bob, new Post('Alice post', $alice), new Post('Bob post', $bob));

        $this->logout();
        $this->enterConsoleContext();

        $posts = $this->repo(Post::class)->findAll();
        $this->assertCount(2, $posts, 'A console/worker run must see all rows through the secured builder.');
    }

    public function testConsoleContextSeesAllAuditLogsWithoutToken(): void
    {
        $this->persist(new AuditLog('user.login'), new AuditLog('payment.processed'));

        $this->logout();
        $this->enterConsoleContext();

        $logs = $this->repo(AuditLog::class)->findAll();
        $this->assertCount(2, $logs, 'Admin-only tables must be fully accessible from a console/worker run.');
    }

    public function testConsoleContextCanFindByIdWithoutToken(): void
    {
        $alice = new User('alice@example.com');
        $post  = new Post('Alice post', $alice);
        $this->persist($alice, $post);

        $this->logout();
        $this->enterConsoleContext();

        $found = $this->repo(Post::class)->find($post->getId());
        $this->assertNotNull($found, 'find() must resolve in a console/worker run despite having no token.');
    }

    public function testWebContextWithoutTokenStillSeesNothing(): void
    {
        // Guard: the console feature must NOT leak into a token-less *web* request.
        $alice = new User('alice@example.com');
        $this->persist($alice, new Post('Secret', $alice));

        $this->logout();   // setUp already established a web context (Request on the stack)

        $this->assertCount(
            0,
            $this->repo(Post::class)->findAll(),
            'A token-less web request must remain fail-closed.',
        );
    }

    // -------------------------------------------------------------------------
    // findBy() with array criteria — Doctrine's ObjectRepository::findBy()
    // contract renders an array value as `field IN (...)`. The secured override
    // must honour that (was: `field = a, b, c` → SQL syntax error 500) while
    // still ANDing the repository's tenant/security filters.
    // -------------------------------------------------------------------------

    public function testFindByArrayCriteriaUsesInForSuperAdmin(): void
    {
        $alice = new User('alice@example.com');
        $admin = new User('admin@example.com', ['ROLE_SUPER_ADMIN']);
        $p1 = new Post('P1', $alice);
        $p2 = new Post('P2', $alice);
        $p3 = new Post('P3', $alice);
        $p4 = new Post('P4', $alice);
        $this->persist($alice, $admin, $p1, $p2, $p3, $p4);

        $this->loginAs($admin);

        // Was: SQLSTATE[42000] syntax error near ', ...' (500).
        $rows = $this->repo(Post::class)->findBy(['id' => [$p1->getId(), $p2->getId(), $p3->getId()]]);
        $this->assertCount(3, $rows, 'Array criteria must render as IN (...), returning every matching row.');

        // p4 was not in the IN list, so IN must actually filter (not just "any of these ids exist").
        $titles = array_map(static fn (Post $p) => $p->getTitle(), $rows);
        sort($titles);
        $this->assertSame(['P1', 'P2', 'P3'], $titles);
    }

    public function testFindBySingleElementArrayCriteria(): void
    {
        $alice = new User('alice@example.com');
        $admin = new User('admin@example.com', ['ROLE_SUPER_ADMIN']);
        $p1 = new Post('P1', $alice);
        $p2 = new Post('P2', $alice);
        $this->persist($alice, $admin, $p1, $p2);

        $this->loginAs($admin);

        $rows = $this->repo(Post::class)->findBy(['id' => [$p1->getId()]]);
        $this->assertCount(1, $rows);
        $this->assertSame('P1', $rows[0]->getTitle());
    }

    public function testFindByEmptyArrayCriteriaReturnsNoRowsWithoutCrashing(): void
    {
        $alice = new User('alice@example.com');
        $admin = new User('admin@example.com', ['ROLE_SUPER_ADMIN']);
        $this->persist($alice, $admin, new Post('P1', $alice), new Post('P2', $alice));

        $this->loginAs($admin);

        // Empty array → matches nothing (mirrors Doctrine), must not crash and must
        // NOT collapse to "no criteria" (which would leak every row to a super admin).
        $this->assertSame([], $this->repo(Post::class)->findBy(['id' => []]));
    }

    public function testFindByEmptyArrayWithOtherCriteriaReturnsNothing(): void
    {
        $alice = new User('alice@example.com');
        $admin = new User('admin@example.com', ['ROLE_SUPER_ADMIN']);
        $this->persist($alice, $admin, new Post('P1', $alice));

        $this->loginAs($admin);

        // The empty IN must dominate even when another criterion would match.
        $this->assertSame([], $this->repo(Post::class)->findBy(['id' => [], 'title' => 'P1']));
    }

    public function testFindByArrayCriteriaStillEnforcesTenantFilter(): void
    {
        $alice = new User('alice@example.com');
        $bob   = new User('bob@example.com');
        $a1 = new Post('Alice 1', $alice);
        $a2 = new Post('Alice 2', $alice);
        $b1 = new Post('Bob 1', $bob);
        $this->persist($alice, $bob, $a1, $a2, $b1);

        $this->loginAs($alice);

        // Alice asks for ids spanning both tenants; IN must be ANDed with her owner
        // filter, so Bob's post must never leak through.
        $rows = $this->repo(Post::class)->findBy(['id' => [$a1->getId(), $a2->getId(), $b1->getId()]]);
        $this->assertCount(2, $rows);
        foreach ($rows as $post) {
            $this->assertSame('alice@example.com', $post->getAuthor()->getEmail());
        }
    }

    public function testFindByArrayCriteriaReturnsEmptyWhenAllIdsBelongToAnotherTenant(): void
    {
        $alice = new User('alice@example.com');
        $bob   = new User('bob@example.com');
        $a1 = new Post('Alice 1', $alice);
        $a2 = new Post('Alice 2', $alice);
        $this->persist($alice, $bob, $a1, $a2);

        $this->loginAs($bob);

        $rows = $this->repo(Post::class)->findBy(['id' => [$a1->getId(), $a2->getId()]]);
        $this->assertSame([], $rows, 'Requesting only another tenant\'s ids must return nothing.');
    }

    public function testFindByEmptyArrayCriteriaWithTenantFilter(): void
    {
        $alice = new User('alice@example.com');
        $this->persist($alice, new Post('Alice 1', $alice));

        $this->loginAs($alice);

        // Empty IN alongside the injected owner andWhere must not crash.
        $this->assertSame([], $this->repo(Post::class)->findBy(['id' => []]));
    }

    public function testFindByArrayCriteriaOnStringField(): void
    {
        $alice = new User('alice@example.com');
        $admin = new User('admin@example.com', ['ROLE_SUPER_ADMIN']);
        $this->persist($alice, $admin, new Post('P1', $alice), new Post('P2', $alice), new Post('P3', $alice));

        $this->loginAs($admin);

        $rows = $this->repo(Post::class)->findBy(['title' => ['P1', 'P3']]);
        $this->assertCount(2, $rows);
    }

    public function testFindByArrayCombinedWithScalarCriteria(): void
    {
        $alice = new User('alice@example.com');
        $admin = new User('admin@example.com', ['ROLE_SUPER_ADMIN']);
        $p1 = new Post('P1', $alice);
        $p2 = new Post('P2', $alice);
        $p3 = new Post('P3', $alice);
        $this->persist($alice, $admin, $p1, $p2, $p3);

        $this->loginAs($admin);

        // Array (IN) and scalar (=) criteria must compose with AND.
        $rows = $this->repo(Post::class)->findBy([
            'id'    => [$p1->getId(), $p2->getId(), $p3->getId()],
            'title' => 'P2',
        ]);
        $this->assertCount(1, $rows);
        $this->assertSame('P2', $rows[0]->getTitle());
    }

    public function testFindByScalarCriteriaStillUsesEquals(): void
    {
        $alice = new User('alice@example.com');
        $admin = new User('admin@example.com', ['ROLE_SUPER_ADMIN']);
        $this->persist($alice, $admin, new Post('P1', $alice), new Post('P2', $alice));

        $this->loginAs($admin);

        $rows = $this->repo(Post::class)->findBy(['title' => 'P2']);
        $this->assertCount(1, $rows);
        $this->assertSame('P2', $rows[0]->getTitle());
    }

    public function testFindByNullCriteriaUsesIsNullAndDoesNotCrash(): void
    {
        $alice = new User('alice@example.com');
        $admin = new User('admin@example.com', ['ROLE_SUPER_ADMIN']);
        $this->persist($alice, $admin, new Post('P1', $alice));

        $this->loginAs($admin);

        // Regression guard: a null value must still render as IS NULL (never `= :param`).
        // No Post has a null title, so this matches nothing — and must not throw.
        $this->assertSame([], $this->repo(Post::class)->findBy(['title' => null]));
    }

    public function testFindOneByArrayCriteriaUsesIn(): void
    {
        $alice = new User('alice@example.com');
        $p1 = new Post('P1', $alice);
        $p2 = new Post('P2', $alice);
        $this->persist($alice, $p1, $p2);

        $this->loginAs($alice);

        // findOneBy delegates to findBy, so it inherits IN handling.
        $found = $this->repo(Post::class)->findOneBy(['id' => [$p1->getId(), $p2->getId()]]);
        $this->assertNotNull($found);
        $this->assertContains($found->getTitle(), ['P1', 'P2']);
    }

    public function testFindOneByEmptyArrayCriteriaReturnsNull(): void
    {
        $alice = new User('alice@example.com');
        $this->persist($alice, new Post('P1', $alice));

        $this->loginAs($alice);

        $this->assertNull($this->repo(Post::class)->findOneBy(['id' => []]));
    }

    public function testFindByArrayCriteriaInConsoleContext(): void
    {
        $alice = new User('alice@example.com');
        $bob   = new User('bob@example.com');
        $a1 = new Post('Alice 1', $alice);
        $b1 = new Post('Bob 1', $bob);
        $this->persist($alice, $bob, $a1, $b1);

        $this->logout();
        $this->enterConsoleContext();

        // Console context grants full access; array criteria still filters by IN.
        $rows = $this->repo(Post::class)->findBy(['id' => [$a1->getId(), $b1->getId()]]);
        $this->assertCount(2, $rows);
    }

    public function testFindByArrayCriteriaOnOpenAccessRepository(): void
    {
        $php     = new Tag('php');
        $symfony = new Tag('symfony');
        $doctrine = new Tag('doctrine');
        $this->persist($php, $symfony, $doctrine);

        $this->logout();

        $rows = $this->repo(Tag::class)->findBy(['id' => [$php->getId(), $doctrine->getId()]]);
        $this->assertCount(2, $rows);

        $this->assertSame([], $this->repo(Tag::class)->findBy(['id' => []]));
    }
}
