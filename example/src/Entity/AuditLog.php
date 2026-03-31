<?php

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Platform-internal records — only ROLE_SUPER_ADMIN can read these.
 * AuditLogRepository uses AdminOnlyAccessRepository.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $action;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    public function __construct(string $action)
    {
        $this->action     = $action;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int           { return $this->id; }
    public function getAction(): string     { return $this->action; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
}
