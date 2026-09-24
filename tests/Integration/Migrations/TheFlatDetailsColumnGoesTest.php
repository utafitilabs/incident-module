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
 * THE FLAT ANSWER BAG IS GONE FROM THE DATABASE.
 *
 * An incident keeps its answers per behaviour block — `incident.block_answers`,
 * in the shape the block asks in. `incident.details` was the flat bag before
 * that, and {@see \Uhifadhi\Incident\Migrations\Version20260912103000} moved
 * every answer with a block to go to into the new column and said, in as many
 * words, that the release after it drops this one.
 *
 * WHY A TEST AND NOT JUST A MIGRATION. A drop is the one change that cannot be
 * un-shipped, so what it removed is asserted rather than assumed: restoring the
 * property on the entity is a one-line edit that would have the next `diff`
 * hand an installation SQL re-adding a column somebody deliberately dropped.
 *
 * THE UNWIND IS REHEARSED NEXT DOOR, by
 * {@see MigrationsUpgradeKeepsDataTest::testTheHistoryUnwindsToNothingAndComesBack},
 * which runs the whole history backwards and forwards again — so this one does
 * not empty the database a second time to say the same thing.
 *
 * @see \Uhifadhi\Incident\Migrations\Version20260924000000
 */
final class TheFlatDetailsColumnGoesTest extends MigrationsTestCase
{
    public function testAnIncidentNoLongerCarriesTheFlatAnswerBag(): void
    {
        $this->migrateToLatest();

        $columns = $this->columnsOf('incident');

        self::assertNotContains('details', $columns, 'The flat answer bag is still on the incident.');
        self::assertContains('block_answers', $columns, 'And the column that replaced it is there.');
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        $names = [];
        foreach ($this->connection()->createSchemaManager()->listTableColumns($table) as $column) {
            $names[] = $column->getName();
        }

        return $names;
    }
}
