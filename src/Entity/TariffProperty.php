<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TariffPropertyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TariffPropertyRepository::class)]
#[ORM\Table(name: 'tariff_property')]
class TariffProperty
{
    public const TYPE_BOOL = 'bool';
    public const TYPE_INT = 'int';
    public const TYPE_STRING = 'string';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 16, unique: true)]
    private string $code = '';

    #[ORM\Column(type: Types::STRING, length: 8)]
    private string $valueType = '';

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = $code; return $this; }
    public function getValueType(): string { return $this->valueType; }
    public function setValueType(string $t): self { $this->valueType = $t; return $this; }
}
