<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A repo-less entity: #[ORM\Entity] with NO repositoryClass, so Doctrine hands
 * out its default EntityRepository — constructed as (EntityManagerInterface,
 * ClassMetadata), NOT the ServiceEntityRepository (ManagerRegistry, entityClass)
 * shape. The factory must build both. This is the case that regressed in
 * be5a121 and is the reason MapEntity(find-by-PK) on such an entity threw a
 * TypeError. It carries no security trait, so it behaves like a plain Doctrine
 * repository (full access) — the point of exercising it is that the factory can
 * construct its repository at all.
 */
#[ORM\Entity]
class TopicPage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $title;

    public function __construct(string $title)
    {
        $this->title = $title;
    }

    public function getId(): ?int      { return $this->id; }
    public function getTitle(): string { return $this->title; }
}
