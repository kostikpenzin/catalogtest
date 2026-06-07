<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\TariffProperty;

/**
 * @extends ServiceEntityRepository<TariffProperty>
 */
class TariffPropertyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TariffProperty::class);
    }

    /**
     * @return array<string, TariffProperty>
     */
    public function findByCodesIndexed(): array
    {
        $out = [];
        foreach ($this->findAll() as $p) {
            $out[$p->getCode()] = $p;
        }
        return $out;
    }
}
