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
 * THE FLAT ANSWER BAG GOES.
 *
 * An incident keeps its answers per behaviour block, in the shape the block
 * asks in — `incident.block_answers`. `incident.details` was the flat bag
 * before that: one name per answer, which could never hold "twelve snares, two
 * carcasses, one bicycle" as the one record it is.
 *
 * @destructive Waits for {@see Version20260912103000}, which moved every answer
 *              with a block to go to into `block_answers` and named this column
 *              as what the next release drops. Nothing has written it since, and
 *              nothing reads it: the property that mapped it goes in the same
 *              commit.
 *
 * THE OTHER HALF OF THAT DEFERRAL IS STILL STANDING.
 * `incident_taxonomy_subcategory.field_set` was named beside this column and is
 * not dropped here — it is a decision of its own and rides its own version, so
 * that a rollback of this one is a rollback of one thing.
 *
 * AND IT SAYS OUT LOUD WHAT GOES WITH IT. Version20260912103000 left two kinds
 * of key where they were — one whose name does not say what it counted
 * (`quantity` answered "3 sacks, dried" under one word and "2 animals" under
 * another) and one no block asks for at all (`enclosure`, `crop`,
 * `circumstances`, `signs`) — and said the release that drops the column is the
 * one that has to say so. This is that release: those answers go with it. They
 * were never read by any screen, and choosing a block for them would have been
 * this module writing somebody's record for them. An installation that wants
 * them keeps a dump taken before the upgrade; docs/upgrading.md says so.
 *
 * THE UNWIND PUTS THE SHAPE BACK, NOT THE ANSWERS. The column returns as the
 * empty object every row carried at the moment the answers moved out; what was
 * in it before that version is in `block_answers` now, and writing a guess of
 * it back would be this migration inventing a record. The tightening runs after
 * the backfill, so a rollback on a table with rows in it lands rather than
 * failing on a NOT NULL nobody could satisfy.
 */
final class Version20260924000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An incident stops carrying the flat answer bag the behaviour blocks replaced.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident DROP details');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident ADD details JSON DEFAULT NULL');
        $this->addSql("UPDATE incident SET details = '{}' WHERE details IS NULL");
        $this->addSql('ALTER TABLE incident ALTER details SET NOT NULL');
    }
}
