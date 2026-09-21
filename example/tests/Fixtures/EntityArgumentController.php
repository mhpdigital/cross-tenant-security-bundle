<?php

namespace App\Tests\Fixtures;

use App\Entity\Country;
use App\Entity\Post;
use App\Entity\Tag;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;

/**
 * Controller signatures only — never routed. EntityArgumentResolutionTest hands these
 * callables to the real argument_resolver service to see what each shape resolves to.
 */
class EntityArgumentController
{
    /** /posts/{id} */
    public function byId(Post $post): void {}

    /** /posts/by-title/{title} */
    public function byMappedField(#[MapEntity(mapping: ['title' => 'title'])] Post $post): void {}

    /** /posts/{post_id} */
    public function byNamedId(#[MapEntity(id: 'post_id')] Post $post): void {}

    /** /posts/{id}/tags/{tag_id} */
    public function twoEntities(Post $post, #[MapEntity(id: 'tag_id')] Tag $tag): void {}

    /** /posts/{id}/tags/{tag} — no attribute; {tag} is matched by argument name */
    public function twoEntitiesByName(Post $post, Tag $tag): void {}

    /** /countries/{code} — primary key is "code", not "id" */
    public function byNaturalKey(#[MapEntity(id: 'code')] Country $country): void {}

    /** /posts/{id} — optional */
    public function nullable(?Post $post): void {}

    /** /posts/{id} — resolution switched off */
    public function disabled(#[MapEntity(disabled: true)] ?Post $post = null): void {}
}
