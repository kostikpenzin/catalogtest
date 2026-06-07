<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TariffJsonbRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TariffJsonbRepository::class)]
#[ORM\Table(name: 'tariff_jsonb')]
class TariffJsonb
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $name = '';

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, name: 'props')]
    private array $props = [];

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getProps(): array { return $this->props; }
    public function setProps(array $p): self { $this->props = $p; return $this; }
}
