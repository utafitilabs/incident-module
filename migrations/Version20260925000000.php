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
 * A SUB-CATEGORY STOPS CARRYING A LIST OF QUESTIONS OF ITS OWN.
 *
 * A word's questions are the questions of the behaviour blocks it switches on —
 * `incident_taxonomy_subcategory.blocks`. `field_set` was a typed list of field
 * names beside that; nothing has written it since
 * {@see Version20260912103000}, and nothing reads it.
 *
 * @destructive Waits for {@see Version20260912103000}, which stopped every
 *              write to this column and named it as what a later release
 *              drops; {@see Version20260924000000} collected the other column
 *              that deferral named and shipped in 0.4.1. The property that
 *              mapped this one goes in the same commit.
 *
 * NOTHING ELSE RIDES ON THE COLUMN: no index, no constraint, no default. It is
 * one statement, so a rollback of this version is a rollback of one thing.
 *
 * THE UNWIND PUTS THE SHAPE BACK, NOT THE LISTS. The column returns as the
 * empty list, tightened after the fill so a rollback on a table with rows in it
 * lands. What a list used to hold is not derivable from the blocks, and writing
 * a guess of it back would be this migration inventing a sub-category.
 */
final class Version20260925000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A sub-category stops carrying a list of questions of its own; its questions are its blocks\'.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident_taxonomy_subcategory DROP field_set');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident_taxonomy_subcategory ADD field_set JSON DEFAULT NULL');
        $this->addSql("UPDATE incident_taxonomy_subcategory SET field_set = '[]' WHERE field_set IS NULL");
        $this->addSql('ALTER TABLE incident_taxonomy_subcategory ALTER field_set SET NOT NULL');
    }
}
