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

namespace Uhifadhi\Incident\Tests\Integration\Migrations;

/**
 * A SUB-CATEGORY CARRIES NO LIST OF QUESTIONS IN THE DATABASE.
 *
 * A word's questions are the questions of the behaviour blocks it switches on
 * — `incident_taxonomy_subcategory.blocks`. `field_set` was a typed list of
 * field names beside that, and
 * {@see \Uhifadhi\Incident\Migrations\Version20260912103000} named it as what a
 * later release drops.
 *
 * WHY A TEST AND NOT JUST A MIGRATION. A drop is the one change that cannot be
 * un-shipped, so what it removed is asserted rather than assumed: restoring the
 * property on the entity is a one-line edit that would have the next `diff`
 * hand an installation SQL re-adding a column somebody deliberately dropped.
 *
 * THE UNWIND IS REHEARSED NEXT DOOR, by
 * {@see MigrationsUpgradeKeepsDataTest::testTheHistoryUnwindsToNothingAndComesBack}.
 *
 * @see \Uhifadhi\Incident\Migrations\Version20260925000000
 */
final class TheQuestionListColumnGoesTest extends MigrationsTestCase
{
    public function testASubcategoryNoLongerCarriesAListOfQuestions(): void
    {
        $this->migrateToLatest();

        $columns = [];
        foreach ($this->connection()->createSchemaManager()->listTableColumns('incident_taxonomy_subcategory') as $column) {
            $columns[] = $column->getName();
        }

        self::assertNotContains('field_set', $columns, 'The typed list of questions is still on the sub-category.');
        self::assertContains('blocks', $columns, 'And the blocks its questions come from are there.');
    }
}
