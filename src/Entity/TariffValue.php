<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TariffValueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TariffValueRepository::class)]
#[ORM\Table(name: 'tariff_value')]
#[ORM\Index(name: 'idx_tv_prop', columns: ['property_id'])]
#[ORM\Index(name: 'idx_tv_prop_int', columns: ['property_id', 'value_int'])]
#[ORM\Index(name: 'idx_tv_prop_str', columns: ['property_id', 'value_string'])]
class TariffValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tariff::class, inversedBy: 'values')]
    #[ORM\JoinColumn(name: 'tariff_id', referencedColumnName: 'id', nullable: false)]
    private Tariff $tariff;

    #[ORM\ManyToOne(targetEntity: TariffProperty::class)]
    #[ORM\JoinColumn(name: 'property_id', referencedColumnName: 'id', nullable: false)]
    private TariffProperty $property;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $valueBool = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $valueInt = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $valueString = null;

    public function getId(): ?int { return $this->id; }
    public function getTariff(): Tariff { return $this->tariff; }
    public function setTariff(Tariff $t): self { $this->tariff = $t; return $this; }
    public function getProperty(): TariffProperty { return $this->property; }
    public function setProperty(TariffProperty $p): self { $this->property = $p; return $this; }
    public function getValueBool(): ?bool { return $this->valueBool; }
    public function setValueBool(?bool $v): self { $this->valueBool = $v; return $this; }
    public function getValueInt(): ?int { return $this->valueInt; }
    public function setValueInt(?int $v): self { $this->valueInt = $v; return $this; }
    public function getValueString(): ?string { return $this->valueString; }
    public function setValueString(?string $v): self { $this->valueString = $v; return $this; }
}
