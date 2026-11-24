<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261124155133 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The second factor of an account: its TOTP secret and the digests of its backup codes';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_user ADD totp_secret VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD backup_codes JSONB DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_user DROP totp_secret');
        $this->addSql('ALTER TABLE app_user DROP backup_codes');
    }
}
