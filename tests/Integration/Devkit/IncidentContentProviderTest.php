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

namespace Uhifadhi\Incident\Tests\Integration\Devkit;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;
use Uhifadhi\Incident\Devkit\DemoMonth;
use Uhifadhi\Incident\Devkit\IncidentContentProvider;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvidence;
use Uhifadhi\Incident\Entity\IncidentParty;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Enum\PartyRoleEnum;
use Uhifadhi\Incident\Model\BlockAnswers;
use Uhifadhi\Incident\Model\IncidentFilter;
use Uhifadhi\Incident\Service\IncidentBlockAnswerService;
use Uhifadhi\Incident\Service\IncidentDashboardService;
use Uhifadhi\Incident\Service\IncidentEvidenceKey;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Storage\Registry\FileRegistry;

/**
 * THE DESIGN'S SAMPLE MONTH, SEEDED THROUGH THE MODULE'S OWN SERVICES — and then
 * read back through the DASHBOARD.
 *
 * {@see \Uhifadhi\Incident\Tests\Unit\Devkit\DemoMonthTest} adds the table up;
 * this proves the table survives being written to a real database through the
 * doors a person uses, and that the widgets then print the numbers the preset
 * gallery states.
 *
 * That last part is the one that matters: every screenshot in the design app is a
 * claim about what the product shows, and this is where that claim is checked.
 */
final class IncidentContentProviderTest extends IntegrationTestCase
{
    private function provider(): ContentProviderInterface
    {
        /** @var ContentProviderInterface $provider */
        $provider = static::getContainer()->get('test_public.incident.devkit.content');

        return $provider;
    }

    /**
     * IT IS DECLARED, AND IT IS THE TAG DEVKIT READS. The tag is written as a
     * literal in this bundle's extension because devkit is absent in production;
     * a module that mistyped it would simply seed nothing, which looks exactly
     * like a module nobody installed.
     */
    public function testItIsRegisteredAsADevkitContentProvider(): void
    {
        $provider = $this->provider();

        self::assertInstanceOf(IncidentContentProvider::class, $provider);
        self::assertSame('incident', $provider->key());
        self::assertSame(['team'], $provider->dependsOn(), 'It is seeded after the people who record its incidents.');
        self::assertNotSame('', $provider->description());
    }

    public function testItFilesTheFortySevenIncidentsTheGalleryTalksAbout(): void
    {
        $this->anArea('Sample Area');

        $this->provider()->load();

        self::assertSame(47, $this->em->getRepository(Incident::class)->count([]));
    }

    /**
     * IT WRITES THE AREA'S OWN WORDS FIRST. Nothing is seeded on install, so a
     * demo that filed before writing them would fail for a reason that looks like
     * a bug and is really an empty vocabulary — and the words it writes are the
     * ones the kinds editor shows, because it writes them through that editor's
     * own service.
     */
    public function testItWritesTheAreasKindsBeforeFilingAnything(): void
    {
        $area = $this->anArea();

        $this->provider()->load();

        self::assertSame('livestock depredation', $this->subcategory($area, 'livestock-depredation')->getLabel());
    }

    /**
     * THE PARTIES THE SAMPLE MONTH NAMES, seeded through the service that now
     * exists. Six of them across four case files — the claimant, the witness and
     * the animal the design's worked example draws.
     */
    public function testItSeedsThePartiesTheSampleMonthNames(): void
    {
        $this->anArea('Sample Area');
        $this->provider()->load();

        self::assertSame(DemoMonth::partyCount(), $this->em->getRepository(IncidentParty::class)->count([]));
    }

    /** An animal is a party too, and the design's worked example is where it appears. */
    public function testTheWorkedExampleCarriesItsAnimal(): void
    {
        $this->anArea('Sample Area');
        $this->provider()->load();

        $roles = array_map(
            static fn (IncidentParty $party): string => $party->getRole()->value,
            $this->em->getRepository(IncidentParty::class)->findAll(),
        );

        self::assertContains(PartyRoleEnum::Animal->value, $roles);
    }

    /**
     * EVERY SEEDED RECORD ANSWERS THE BLOCKS ITS WORD SWITCHED ON — every one of
     * their DEFINING questions, because a seeded record the report form would have
     * refused is a demo that teaches the wrong rule.
     */
    public function testEverySeededIncidentAnsweredTheBlocksItsWordAsks(): void
    {
        $this->anArea('Sample Area');
        $this->provider()->load();

        /** @var IncidentBlockAnswerService $gate */
        $gate = static::getContainer()->get('test_public.incident.block_answers');

        $unanswered = [];
        foreach ($this->em->getRepository(Incident::class)->findAll() as $incident) {
            $answers = new BlockAnswers($incident->getBlockAnswers(), $incident->getClaimedAtFiling());
            foreach ($gate->missing($incident->getSubcategory(), $answers) as $missing) {
                $unanswered[] = $incident->getReference().' · '.$incident->getSubcategory()->getCode().' · '.$missing;
            }
        }

        self::assertSame([], $unanswered);
    }

    /** And the figure the money block asked is the claimed one, not a money record. */
    public function testTheFigureAskedAtFilingIsKeptApartFromTheMoneyRecord(): void
    {
        $this->anArea('Sample Area');
        $this->provider()->load();

        $claimed = 0;
        $judged = 0;
        foreach ($this->em->getRepository(Incident::class)->findAll() as $incident) {
            if (null !== $incident->getClaimedAtFiling()) {
                ++$claimed;
            }
            if (null !== $incident->getMoney()) {
                ++$judged;
            }
        }

        // Every word that carries money asked its figure at filing, and far fewer
        // records have reached the state where somebody judges one.
        self::assertGreaterThan($judged, $claimed);
    }

    /**
     * EVIDENCE WITH REAL BYTES BEHIND IT. The point of seeding it through the
     * service rather than writing rows is that the hub then reads a genuine size
     * and a genuine preview back — a row with a key and no blob would link at a
     * 404 and be listed nowhere.
     */
    public function testItSeedsEvidenceWithBytesBehindIt(): void
    {
        $this->anArea('Sample Area');
        $this->provider()->load();

        $evidence = $this->em->getRepository(IncidentEvidence::class)->findAll();
        self::assertSame(DemoMonth::evidenceCount(), \count($evidence));

        foreach ($evidence as $item) {
            self::assertNotNull($item->getPath(), 'Seeded evidence must be keyed, not merely recorded.');
            self::assertGreaterThan(0, (int) $item->getByteSize());
            self::assertNotNull($item->getThumbKey(), 'A photograph the machine can decode gets its preview.');
        }
    }

    /** Every seeded key is one this module's own voter claims, or nobody may look at it. */
    public function testEverySeededEvidenceKeyIsClaimedByThisModule(): void
    {
        $this->anArea('Sample Area');
        $this->provider()->load();

        foreach ($this->em->getRepository(IncidentEvidence::class)->findAll() as $item) {
            self::assertTrue(IncidentEvidenceKey::claims((string) $item->getPath()));
        }
    }

    /** So the module appears on the hub holding what it actually holds. */
    public function testTheSeededEvidenceReachesTheFilesHub(): void
    {
        $this->anArea('Sample Area');
        $this->provider()->load();

        /** @var FileRegistry $registry */
        $registry = $this->service('storage.file_registry');

        self::assertCount(DemoMonth::evidenceCount(), $registry->all());
    }

    /**
     * THE ASSIGNEE, WHICH NOTHING USED TO WRITE. Response is somebody's work, so
     * an incident that has reached `in progress` is carrying somebody's name —
     * and one that has not is carrying nobody's, which is the honest drawing of
     * "not started".
     */
    public function testResponseIsAssignedAndNothingElseIs(): void
    {
        $this->anArea('Sample Area');
        $this->aUser('j.mollel@example.test', 'Joseph', 'Mollel');
        $this->provider()->load();

        foreach ($this->em->getRepository(Incident::class)->findAll() as $incident) {
            $reached = $incident->getStatus()->hasReached(IncidentStatusEnum::InProgress);
            self::assertSame(
                $reached,
                null !== $incident->getAssignedTo(),
                \sprintf('%s is %s and %s assigned.', (string) $incident->getReference(), $incident->getStatus()->value, null === $incident->getAssignedTo() ? 'not' : ''),
            );
        }
    }

    /**
     * THE DEMO OPENS ON A POPULATED DASHBOARD, which is the only reason to seed
     * one. The dashboard's default window is the CURRENT month; a sample month
     * pinned to a date in the past put all forty-seven incidents just out of view
     * and a freshly seeded installation opened on "0 filed" and an empty register.
     */
    public function testMostOfTheSampleMonthLandsInsideTheCurrentMonth(): void
    {
        $this->anArea('Sample Area');
        $this->provider()->load();

        $monthStart = new \DateTimeImmutable()->modify('first day of this month')->setTime(0, 0);
        $inThisMonth = 0;
        foreach ($this->em->getRepository(Incident::class)->findAll() as $incident) {
            if ($incident->getReportedAt() >= $monthStart) {
                ++$inThisMonth;
            }
        }

        // At LEAST the recent rows: when the month is older than three weeks the
        // recent window starts three weeks back and the older rows that fill the
        // days before it still fall inside this month.
        self::assertGreaterThanOrEqual(DemoMonth::RECENT_COUNT, $inThisMonth);
    }

    /** And nothing is filed in the future, whatever day of the month it is run on. */
    public function testNothingIsReportedAfterToday(): void
    {
        $this->anArea('Sample Area');
        $this->provider()->load();

        $endOfToday = new \DateTimeImmutable()->setTime(23, 59, 59);
        foreach ($this->em->getRepository(Incident::class)->findAll() as $incident) {
            self::assertLessThanOrEqual($endOfToday, $incident->getReportedAt(), (string) $incident->getReference());
        }
    }

    /**
     * DEMO CONTENT IS PLACED IN THE INSTALLATION'S AREA, whatever area that is.
     *
     * The provider used to carry fixed fixture coordinates, and those coordinates
     * had been shifted sixty-five degrees west when the client names were purged.
     * Nothing noticed, because nothing asked the one question that matters: is the
     * point inside the boundary? On a real installation every one of the
     * forty-seven landed in the open Atlantic, the dashboard map fitted itself to
     * a blob a continent away from the area, and `st_within` answered 0 of 47.
     *
     * So the boundary is now the only thing that decides where a demo incident
     * is, and this asks PostGIS the question directly rather than comparing
     * numbers in PHP.
     */
    public function testEveryIncidentIsPlacedInsideTheAreasOwnBoundary(): void
    {
        $area = $this->anArea('Sample Area');
        $this->provider()->load();

        $total = $this->em->getRepository(Incident::class)->count([]);
        self::assertSame(47, $total, 'The seeding has to have left something to place.');

        $within = $this->countBySql(
            'SELECT count(*) FROM incident i JOIN area_of_interest a ON a.id = i.area_id
             WHERE ST_Within(i.position, a.geom)',
        );

        self::assertSame($total, $within, 'Every seeded incident belongs inside the area it was filed in.');
        self::assertNotNull($area->getGeom());
    }

    /**
     * AND NOWHERE NEAR THE COORDINATES THAT WERE WRONG — asked of an area that is
     * NOT sitting on them.
     *
     * That qualification is the point. This suite's own area fixture had been
     * shifted the same sixty-five degrees as the provider's, so it contained the
     * bad points and a within-the-boundary assertion passed against both halves
     * of the same mistake. An area somewhere else is what makes the question
     * answerable.
     */
    public function testNothingIsSeededAtTheOldFixtureCoordinates(): void
    {
        $this->anAreaSomewhereElse();
        $this->provider()->load();

        $stray = $this->countBySql('SELECT count(*) FROM incident WHERE ST_X(position) BETWEEN -30.0 AND -29.0');

        self::assertSame(0, $stray, 'The shifted fixture coordinates must not survive anywhere.');
    }

    /**
     * TWO AREAS, TWO PLACES. The points are the boundary's, so seeding into a
     * different area puts them somewhere different — which is the whole claim.
     */
    public function testTheSameSampleMonthLandsWhereverTheAreaIs(): void
    {
        $this->anAreaSomewhereElse();

        $this->provider()->load();

        $outside = $this->countBySql('SELECT count(*) FROM incident WHERE ST_X(position) NOT BETWEEN -22.0 AND -21.2');

        self::assertSame(0, $outside);
    }

    /**
     * AN AREA WITH NO BOUNDARY HAS NOWHERE HONEST TO PUT ANYTHING. An area is
     * gazetted and named before its boundary is imported, so a boundaryless area
     * is a real state and not a broken row; inventing a coordinate for it is
     * exactly the bug above, and seeding nothing is the honest answer.
     */
    public function testAnAreaWithNoBoundaryIsSeededNothingAndDoesNotThrow(): void
    {
        // Built here rather than through the fixture helper, because an area gets
        // its boundary from an import and setGeom() has no way to take it away.
        $area = new AreaOfInterest();
        $area->setName('Not Yet Surveyed')->setSource('test fixture');
        $this->em->persist($area);
        $this->em->flush();
        self::assertFalse($area->hasBoundary());

        $this->provider()->load();

        self::assertSame(0, $this->em->getRepository(Incident::class)->count([]));
    }

    /** A count asked of PostGIS, which is the only thing that can answer where an inside is. */
    private function countBySql(string $sql): int
    {
        $count = $this->em->getConnection()->fetchOne($sql);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * An area that is nowhere near the coordinates the provider used to carry, so
     * "inside the boundary" and "not at the old place" are two questions rather
     * than one.
     */
    private function anAreaSomewhereElse(): AreaOfInterest
    {
        $area = $this->anArea('Sample Area');
        $area->setGeom('{"type":"MultiPolygon","coordinates":[[[[-22.0,-3.5],[-21.2,-3.5],[-21.2,-2.7],[-22.0,-2.7],[-22.0,-3.5]]]]}');
        $this->em->flush();

        return $area;
    }

    /**
     * AN INSTALLATION WITH NO AREA HAS NOWHERE TO FILE, and that is a state
     * rather than a failure — devkit seeds every module in one run, and one with
     * nothing to hang its records on must not stop the others.
     */
    public function testWithNoAreaItFilesNothingAndDoesNotThrow(): void
    {
        $this->provider()->load();

        self::assertSame(0, $this->em->getRepository(Incident::class)->count([]));
    }

    /**
     * THE WORKFLOW WAS WALKED, NOT WRITTEN. Every seeded incident got where it is
     * one legal transition at a time, through the real service — so the seeding
     * cannot produce a state the product could not, and every one has a timeline.
     */
    public function testEverySeededIncidentWalkedTheRealWorkflow(): void
    {
        $this->anArea();
        $this->provider()->load();
        $this->em->clear();

        foreach ($this->em->getRepository(Incident::class)->findAll() as $incident) {
            // The filing, plus one event per transition it took to get here —
            // plus a money event where an amount was recorded on the way.
            self::assertGreaterThanOrEqual(
                $incident->getStatus()->step(),
                $incident->getEvents()->count(),
                \sprintf('%s is %s but its timeline is shorter than the walk that got it there.', $incident->getReference(), $incident->getStatus()->value),
            );
        }
    }

    /**
     * THE REFERENCE IS THE REGISTER'S. Filing mints the next one, exactly as it
     * does for a person at the form, rather than carrying the sample month's own
     * strings past the register that hands them out.
     */
    public function testEveryReferenceWasMintedByTheRegister(): void
    {
        $this->anArea();
        $this->provider()->load();
        $this->em->clear();

        foreach ($this->em->getRepository(Incident::class)->findAll() as $incident) {
            self::assertStringStartsWith('INC-', (string) $incident->getReference());
        }
    }

    /**
     * THE GALLERY'S OWN NUMBERS, printed by the dashboard that reads the seeded
     * rows.
     *
     * MONEY IS THE ONE FIGURE THAT DIFFERS FROM THE DESIGN, and deliberately: the
     * product records money once response has started, and sixteen rows of the
     * sample month carry money at `reported` or `verified`. Those figures are not
     * written, because writing them would mean seeding a state no screen can
     * produce. What is asserted here is therefore the money the PRODUCT can hold.
     */
    public function testTheDashboardPrintsTheGallerysOwnNumbers(): void
    {
        $area = $this->anArea();
        $this->provider()->load();
        $this->em->clear();

        /** @var IncidentDashboardService $service */
        $service = $this->service('incident.dashboard');
        $area = $this->em->getRepository($area::class)->find($area->getId());
        self::assertNotNull($area);

        // THE WHOLE SPAN, because the sample month is now measured back from
        // today rather than pinned to a date — see DemoMonth::reportedAt().
        $today = new \DateTimeImmutable()->setTime(23, 59, 59);
        $dashboard = $service->build(
            new IncidentFilter($area, $today->modify(\sprintf('-%d days', DemoMonth::SPAN_DAYS + 1)), $today),
            $today,
        );

        self::assertSame(47, $dashboard->filedCount, 'The gallery says 47 filed.');
        self::assertSame(31, $dashboard->openCount(), 'The gallery says 31 still open.');
        self::assertSame(7, $dashboard->statusCount(IncidentStatusEnum::Reported));
        self::assertSame(13, $dashboard->statusCount(IncidentStatusEnum::Verified));
        self::assertSame(11, $dashboard->statusCount(IncidentStatusEnum::InProgress));

        // 18 conflict · 12 poaching · 9 compliance · 8 mortality.
        self::assertSame(18, $dashboard->kindCounts['conflict']);
        self::assertSame(12, $dashboard->kindCounts['poaching']);
        self::assertSame(9, $dashboard->kindCounts['compliance']);
        self::assertSame(8, $dashboard->kindCounts['mortality']);

        // And the funnel: 16 reached resolved, 5 reached closed.
        $reached = $dashboard->reachedCounts();
        self::assertSame(47, $reached[IncidentStatusEnum::Reported->value]);
        self::assertSame(16, $reached[IncidentStatusEnum::Resolved->value]);
        self::assertSame(5, $reached[IncidentStatusEnum::Closed->value]);

        // Money, from the rows that had reached response by the time it was
        // recorded — never a claim on an incident nobody has responded to.
        self::assertGreaterThan(0, $dashboard->money[MoneyDirectionEnum::Fine->value]['approved']);
        self::assertGreaterThan(0, $dashboard->money[MoneyDirectionEnum::Compensation->value]['approved']);
    }

    /**
     * ZONES ARE THE MAP'S ANSWER, NOT A NAME MATCH. Filing asks PostGIS which
     * zone the point falls in, so an area with a zone drawn over the sample
     * month's positions gets its incidents zoned and one without gets none —
     * unzoned being a first-class answer rather than a failure.
     */
    public function testZonesAreAttachedWhereTheAreaHasThemDrawn(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'North Gate', -30.0, -29.5);
        $this->provider()->load();
        $this->em->clear();

        $unzoned = $this->em->getRepository(Incident::class)->findBy(['zone' => null]);
        self::assertLessThan(47, \count($unzoned), 'The incidents inside the drawn zone should have found it.');
    }
}
