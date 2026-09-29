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

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvent;
use Uhifadhi\Incident\Entity\IncidentEvidence;
use Uhifadhi\Incident\Entity\IncidentMoney;
use Uhifadhi\Incident\Entity\IncidentParty;
use Uhifadhi\Incident\Tests\Integration\Fixtures\CollectedContentProviders;

/**
 * THE UPGRADE REHEARSAL: rows that are already there when the next version runs
 * are still there afterwards.
 *
 * The rows are not written here. They are seeded by this module's OWN content
 * provider, through the services a person's screens use — so what is asserted to
 * survive is shaped the way real content is, including the workflow history and
 * the money rows a hand-written fixture would never have produced.
 *
 * HOW THE SEEDING IS DRIVEN, said plainly, because the provider needs two things
 * it does not create:
 *
 *   PEOPLE — `IncidentContentProvider::dependsOn()` returns `['team']`, and the
 *   provider records incidents against accounts the installation already has. So
 *   TeamBundle's own content provider is run first, reached through
 *   {@see CollectedContentProviders} — devkit's collector, played by a fixture —
 *   which is the same door devkit uses and keeps the dependency honest.
 *
 *   AN AREA — nothing installed ships area seed content, which is why the
 *   provider takes the first area the installation has and files nothing when
 *   there is none. There is no provider to drive, so the area is the smallest
 *   honest fixture: one persisted AreaOfInterest with the boundary its NOT NULL
 *   columns require.
 *
 * The round trip is a separate test, and it is deliberately not a data-safety
 * claim: a version that creates a table has a `down()` that drops it, and
 * dropping a table drops its rows. What `down()` guarantees is that the schema
 * comes back, which is what makes it worth shipping.
 */
final class MigrationsUpgradeKeepsDataTest extends MigrationsTestCase
{
    public function testContentSeededThroughTheModulesServicesSurvivesTheNextMigrate(): void
    {
        $this->migrateToLatest();
        $this->seedAMonthOfIncidents();

        $before = $this->counts();
        self::assertGreaterThan(0, $before['incident'], 'The seeding has to have left something to protect.');

        $this->migrateToLatest();

        self::assertSame($before, $this->counts());
    }

    /**
     * THE WHOLE HISTORY UNWINDS AND COMES BACK. An installation that has to roll
     * a release back reaches for `migrations:migrate prev`, and a `down()` that
     * was never run is a `down()` nobody has any reason to trust.
     */
    public function testTheHistoryUnwindsToNothingAndComesBack(): void
    {
        $this->migrateToLatest();
        $this->migrateToEmpty();

        $emptied = $this->connection()->createSchemaManager()->listTableNames();
        self::assertNotContains('incident', $emptied, 'down() has to actually drop what up() created.');

        $this->migrateToLatest();

        $rebuilt = $this->connection()->createSchemaManager()->listTableNames();
        self::assertContains('incident', $rebuilt);
        self::assertContains('incident_money', $rebuilt);
        self::assertContains('incident_party', $rebuilt);
        self::assertContains('incident_evidence', $rebuilt);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach ([
            'incident' => Incident::class,
            'incident_event' => IncidentEvent::class,
            'incident_money' => IncidentMoney::class,
            // The three the provider could not write until it had services for
            // them. A rehearsal that only ever protected incidents and their
            // events was rehearsing against half the tables this module owns.
            'incident_party' => IncidentParty::class,
            'incident_evidence' => IncidentEvidence::class,
        ] as $table => $entity) {
            $counts[$table] = $this->em->getRepository($entity)->count([]);
        }

        return $counts;
    }

    private function seedAMonthOfIncidents(): void
    {
        /** @var CollectedContentProviders $providers */
        $providers = static::getContainer()->get('test_public.devkit.content_providers');
        $byKey = $providers->byKey();

        self::assertArrayHasKey('team', $byKey, 'The people this module files incidents against come from team.');
        $byKey['team']->load();

        $this->anArea();

        self::assertArrayHasKey('incident', $byKey);
        $incident = $byKey['incident'];
        self::assertInstanceOf(ContentProviderInterface::class, $incident);
        $incident->load();

        $this->em->clear();

        // Seeded through the real doors, so the rows carry a history, figures,
        // the people involved and evidence with bytes behind it — the things a
        // schema change is most likely to break, and the things a hand-written
        // fixture would never have produced.
        self::assertGreaterThan(0, $this->em->getRepository(Incident::class)->count([]));
        self::assertGreaterThan(0, $this->em->getRepository(IncidentEvent::class)->count([]));
        self::assertGreaterThan(0, $this->em->getRepository(IncidentMoney::class)->count([]));
        self::assertGreaterThan(0, $this->em->getRepository(IncidentParty::class)->count([]));
        self::assertGreaterThan(0, $this->em->getRepository(IncidentEvidence::class)->count([]));
    }

    private function anArea(): void
    {
        $area = new AreaOfInterest();
        $area->setName('Sample Area');
        $area->setSource('test fixture');
        $area->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}');
        $this->em->persist($area);
        $this->em->flush();
    }
}
