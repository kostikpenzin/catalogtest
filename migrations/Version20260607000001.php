<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use App\Catalog\PropertyCatalog;

final class Version20260607000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catalog: tariff_flat (100 columns, no extra indexes)';
    }

    public function up(Schema $schema): void
    {
        $cols = ['id SERIAL PRIMARY KEY', 'name VARCHAR(64) NOT NULL'];
        foreach (PropertyCatalog::all() as $p) {
            $col = match ($p['type']) {
                'bool' => 'p_b_' . substr($p['code'], 1),
                'int' => 'p_i_' . substr($p['code'], 1),
                'string' => 'p_s_' . substr($p['code'], 1),
            };
            $type = match ($p['type']) {
                'bool' => 'BOOLEAN NOT NULL',
                'int' => 'INTEGER NOT NULL',
                'string' => 'VARCHAR(64) NOT NULL',
            };
            $cols[] = $col . ' ' . $type;
        }
        $this->addSql('CREATE TABLE tariff_flat (' . implode(', ', $cols) . ')');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS tariff_flat');
    }
}
