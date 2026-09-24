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
 * THE ANSWERS MOVE INTO THE SHAPE THE BLOCKS ASK IN, AND NOTHING RECORDED IS
 * LEFT BEHIND WITHOUT SAYING SO.
 *
 * An installation that has been filing incidents has a flat answer per question
 * name — `species`, `snares_lifted`, `suspects`, `road_segment` — because the
 * form asked a per-sub-category list of field names. The questions come from the
 * blocks now, so those answers have to arrive where the blocks keep them: the
 * singles in the block's own object, the lists as rows.
 *
 * The rows are written in RAW SQL at the version before the one that moves them,
 * because no code left in this module can write the flat shape any more — a
 * fixture built from the current entities would be rehearsing the migration
 * against a database that cannot exist.
 *
 * WHAT IS DELIBERATELY NOT MOVED is asserted too: a key whose NAME does not say
 * what was counted or who somebody was cannot be turned into a block answer
 * without putting words in the record, so it stays where it is, in a column this
 * release keeps.
 *
 * @see \Uhifadhi\Incident\Migrations\Version20260912103000
 */
final class FlatDetailsBecomeBlockAnswersTest extends MigrationsTestCase
{
    /** The version the flat shape is written at — the last one before the move. */
    private const string BEFORE_THE_BLOCKS = 'Uhifadhi\Incident\Migrations\Version20260911140000';

    /** The last version that still has `incident.details` on the table. */
    private const string BEFORE_THE_DROP = 'Uhifadhi\Incident\Migrations\Version20260921000000';

    /** The answers one incident carries under a flat name per question. */
    private const array FLAT_DETAILS = [
        'species' => 'Lion',
        'sex' => 'female',
        'age_class' => 'adult',
        'snares_lifted' => '12 wire snares',
        'method' => 'Wire cable, anchored',
        'gear' => 'Cable',
        'vehicle' => 'Not identified',
        'suspects' => 'Two men, detained',
        'occupier' => 'Named on the notice',
        'seizures' => 'Snares, one bicycle',
        'trophy' => '2 worked pieces',
        'condition' => 'No injury pattern',
        'carcass_disposition' => 'Left in situ',
        'samples_taken' => 'Two swabs',
        'injuries' => 'Leg and hand',
        'treatment' => 'A health centre',
        'area_affected' => '3 ha',
        'land_use' => 'Cultivation',
        'road_segment' => 'C-road, km 12',
        'permit_status' => 'None held',
        'licence_status' => 'None held',
        'notice_served' => 'Served on site',
        // Neither of these says what it counted or who anybody was.
        'quantity' => '3 sacks, dried',
        'circumstances' => 'At night, herding',
    ];

    public function testEveryFlatAnswerWithABlockToGoToArrivesInIt(): void
    {
        $this->migrateTo(self::BEFORE_THE_BLOCKS);
        $this->seedTheFlatShape();

        $this->migrateToLatest();

        self::assertSame(self::sorted([
            'species' => [
                'age_class' => 'adult',
                'sex' => 'female',
                'species' => 'Lion',
            ],
            'counts' => [
                'rows' => [
                    ['how_many' => '12 wire snares', 'quantity' => 'snares lifted'],
                ],
            ],
            'method' => [
                'gear' => 'Cable',
                'method' => 'Wire cable, anchored',
                'vehicle' => 'Not identified',
            ],
            'parties' => [
                'rows' => [
                    ['name' => 'Two men, detained', 'role' => 'suspect'],
                    ['name' => 'Named on the notice'],
                ],
            ],
            'seizures' => [
                'rows' => [
                    ['item' => 'Snares, one bicycle'],
                    ['item' => '2 worked pieces'],
                ],
            ],
            'condition' => [
                'carcass_disposition' => 'Left in situ',
                'condition' => 'No injury pattern',
            ],
            'samples' => [
                'rows' => [
                    ['samples_taken' => 'Two swabs'],
                ],
            ],
            'casualty' => [
                'rows' => [
                    ['injuries' => 'Leg and hand', 'treatment' => 'A health centre'],
                ],
            ],
            'extent' => [
                'land_use' => 'Cultivation',
                'rows' => [
                    ['measure' => 'area affected · ha', 'value' => '3 ha'],
                ],
            ],
            'named-place' => [
                'place_kind' => 'road segment',
                'place_name' => 'C-road, km 12',
            ],
            'notice' => [
                'licence_status' => 'None held',
                'notice_served' => 'Served on site',
                'permit_status' => 'None held',
            ],
        ]), self::sorted($this->blockAnswers()));
    }

    /**
     * THE MONEY FIGURE IS NOT INVENTED FROM A JUDGEMENT. What a money record
     * holds was written by whoever assessed or approved it, in a state past
     * filing; calling it "claimed at filing" afterwards would date somebody
     * else's figure to a moment nobody recorded it at.
     */
    public function testTheClaimedFigureIsNotBackfilledFromTheMoneyRecord(): void
    {
        $this->migrateTo(self::BEFORE_THE_BLOCKS);
        $this->seedTheFlatShape();

        $this->migrateToLatest();

        self::assertNull($this->connection()->fetchOne('SELECT claimed_at_filing FROM incident'));
    }

    /**
     * A KEY WITH NO BLOCK TO GO TO IS NOT THROWN AWAY BY THIS VERSION. It stays
     * in the column, where an installation can still read what a record said,
     * for as long as the column is there.
     *
     * SO THIS READS AT THE LAST VERSION BEFORE THE DROP, not at latest.
     * {@see \Uhifadhi\Incident\Migrations\Version20260924000000} takes the
     * column away, and with it every answer that never had a block to go to —
     * which is the thing that version exists to say out loud.
     */
    public function testAnAnswerWithNoBlockToGoToStaysWhereItWas(): void
    {
        $this->migrateTo(self::BEFORE_THE_BLOCKS);
        $this->seedTheFlatShape();

        $this->migrateTo(self::BEFORE_THE_DROP);

        $details = $this->connection()->fetchOne('SELECT details FROM incident');
        self::assertIsString($details);

        /** @var array<string, string> $decoded */
        $decoded = json_decode($details, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('3 sacks, dried', $decoded['quantity'] ?? null);
        self::assertSame('At night, herding', $decoded['circumstances'] ?? null);

        $blocks = $this->blockAnswers();
        self::assertArrayNotHasKey('quantity', $blocks['counts'] ?? []);
    }

    /** An incident that answered nothing gets an empty object, never a null. */
    public function testAnIncidentThatAnsweredNothingGetsAnEmptyShape(): void
    {
        $this->migrateTo(self::BEFORE_THE_BLOCKS);
        $this->seedTheFlatShape([]);

        $this->migrateToLatest();

        self::assertSame([], $this->blockAnswers());
    }

    /** @return array<string, array<string, mixed>> */
    private function blockAnswers(): array
    {
        $stored = $this->connection()->fetchOne('SELECT block_answers FROM incident');
        self::assertIsString($stored, 'Every incident carries a block-answers object.');

        /** @var array<string, array<string, mixed>> $decoded */
        $decoded = json_decode($stored, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * The stored object's keys are the database's business — jsonb keeps its own
     * order — so the comparison is made on sorted keys and the ROWS keep theirs,
     * which is the only order that carries meaning.
     *
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private static function sorted(array $values): array
    {
        foreach ($values as $key => $value) {
            if (\is_array($value)) {
                $values[$key] = self::sorted($value);
            }
        }

        if (!array_is_list($values)) {
            ksort($values);
        }

        return $values;
    }

    /**
     * One area, one word, one incident — and a flat answer per question name,
     * written straight into the column that holds them.
     *
     * @param array<string, string>|null $details null for the whole flat set
     */
    private function seedTheFlatShape(?array $details = null): void
    {
        $connection = $this->connection();

        $connection->executeStatement(<<<'SQL'
            INSERT INTO area_of_interest (uuid, name, source, geom)
            VALUES (gen_random_uuid(), 'Test Reserve', 'test fixture',
                    ST_GeomFromGeoJSON('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}'))
            SQL);

        $connection->executeStatement(<<<'SQL'
            INSERT INTO incident_taxonomy_kind (uuid, code, label, colour_key, leads, position, active, area_id, created_at, updated_at)
            SELECT gen_random_uuid(), 'conflict', 'Conflict', 'hwc', '[]', 0, TRUE, a.id, NOW(), NOW()
            FROM area_of_interest a WHERE a.name = 'Test Reserve'
            SQL);

        $connection->executeStatement(<<<'SQL'
            INSERT INTO incident_taxonomy_subcategory (uuid, code, label, blocks, money_direction, term_hours, field_set, position, active, kind_id, created_at, updated_at)
            SELECT gen_random_uuid(), 'depredation', 'depredation', '[]', NULL, 720, '[]', 0, TRUE, k.id, NOW(), NOW()
            FROM incident_taxonomy_kind k WHERE k.code = 'conflict'
            SQL);

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO incident (uuid, reference, title, position, status, severity, source, reported_at, details, area_id, taxonomy_subcategory_id, created_at, updated_at)
                SELECT gen_random_uuid(), 'INC-0001', 'A filed incident', ST_SetSRID(ST_MakePoint(-29.5, -3.2), 4326),
                       'reported', 'moderate', 'direct', NOW(), CAST(:details AS json), a.id, s.id, NOW(), NOW()
                FROM area_of_interest a, incident_taxonomy_subcategory s
                WHERE a.name = 'Test Reserve' AND s.code = 'depredation'
                SQL,
            ['details' => json_encode($details ?? self::FLAT_DETAILS, \JSON_THROW_ON_ERROR)],
        );
    }
}
