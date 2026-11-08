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
 * AN INCIDENT FILED OUTSIDE ITS AREA'S BOUNDARY is marked so. Every incident
 * filed before is unmarked: nothing judged its point then.
 */
final class Version20261108120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'incident.outside_area';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident ADD outside_area BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident DROP outside_area');
    }
}
