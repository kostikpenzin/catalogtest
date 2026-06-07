<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260607000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catalog: tariff_jsonb with GIN index on props';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tariff_jsonb (id SERIAL PRIMARY KEY, name VARCHAR(64) NOT NULL, props JSONB NOT NULL)');
        $this->addSql('CREATE INDEX idx_tariff_jsonb_props ON tariff_jsonb USING GIN (props jsonb_path_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS tariff_jsonb');
    }
}
