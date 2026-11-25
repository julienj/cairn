<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261124152916 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'What an account leaves out of the thread of the issues';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD hidden_activity JSONB DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP hidden_activity');
    }
}
