<?php

namespace App\Entity;

use App\Repository\CountryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lookup table whose primary key is NOT called "id" — the ISO code is the key.
 * Exercises find()-by-PK (and so #[MapEntity]) on a natural-key entity.
 */
#[ORM\Entity(repositoryClass: CountryRepository::class)]
class Country
{
    #[ORM\Id]
    #[ORM\Column(length: 2)]
    private string $code;

    #[ORM\Column(length: 100)]
    private string $name;

    public function __construct(string $code, string $name)
    {
        $this->code = $code;
        $this->name = $name;
    }

    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
}
