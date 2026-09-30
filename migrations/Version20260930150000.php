<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Incidents Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Incident\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AN INCIDENT FILED FROM A RECORD SINCE DELETED (ruled 28 Sep, #48): when it
 * lost its link. Schema only: nullable, and nothing has been deleted before.
 */
final class Version20260930150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'incident.source_record_deleted_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident ADD source_record_deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident DROP source_record_deleted_at');
    }
}
