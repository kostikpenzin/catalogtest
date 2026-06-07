<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260607000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catalog: tariff_rel + tariff_property + tariff_value (EAV-lite)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tariff_rel (id SERIAL PRIMARY KEY, name VARCHAR(64) NOT NULL)');
        $this->addSql("CREATE TABLE tariff_property (id SERIAL PRIMARY KEY, code VARCHAR(16) NOT NULL UNIQUE, value_type VARCHAR(8) NOT NULL)");
        $this->addSql('CREATE TABLE tariff_value (
            id BIGSERIAL PRIMARY KEY,
            tariff_id INTEGER NOT NULL REFERENCES tariff_rel(id) ON DELETE CASCADE,
            property_id INTEGER NOT NULL REFERENCES tariff_property(id) ON DELETE CASCADE,
            value_bool BOOLEAN,
            value_int INTEGER,
            value_string VARCHAR(64)
        )');
        $this->addSql('CREATE INDEX idx_tv_tariff ON tariff_value (tariff_id)');
        $this->addSql('CREATE INDEX idx_tv_prop_int ON tariff_value (property_id, value_int)');
        $this->addSql('CREATE INDEX idx_tv_prop_str ON tariff_value (property_id, value_string)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS tariff_value');
        $this->addSql('DROP TABLE IF EXISTS tariff_property');
        $this->addSql('DROP TABLE IF EXISTS tariff_rel');
    }
}
