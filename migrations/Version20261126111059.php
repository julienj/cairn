<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261126111059 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The team an issue is assigned to';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issue ADD assignee_team_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              issue
            ADD
              CONSTRAINT FK_12AD233E348F7462 FOREIGN KEY (assignee_team_id) REFERENCES team (id)
        SQL);
        $this->addSql('CREATE INDEX IDX_12AD233E348F7462 ON issue (assignee_team_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issue DROP CONSTRAINT FK_12AD233E348F7462');
        $this->addSql('DROP INDEX IDX_12AD233E348F7462');
        $this->addSql('ALTER TABLE issue DROP assignee_team_id');
    }
}
