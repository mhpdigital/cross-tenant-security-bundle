<?php

namespace App\Entity;

use App\Repository\PostRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tenant-scoped entity — each user sees only their own posts.
 * PostRepository uses CrossTenantRepository and adds an owner filter.
 */
#[ORM\Entity(repositoryClass: PostRepository::class)]
class Post
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $author;

    public function __construct(string $title, User $author)
    {
        $this->title  = $title;
        $this->author = $author;
    }

    public function getId(): ?int    { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function getAuthor(): User  { return $this->author; }
}
