<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TariffRelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TariffRelRepository::class)]
#[ORM\Table(name: 'tariff_rel')]
class Tariff
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $name = '';

    /**
     * @var Collection<int, TariffValue>
     */
    #[ORM\OneToMany(mappedBy: 'tariff', targetEntity: TariffValue::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $values;

    public function __construct()
    {
        $this->values = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getValues(): Collection { return $this->values; }
}
