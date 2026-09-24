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

namespace Uhifadhi\Incident\Tests\Integration\Overview;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Overview\ContributesStylesheetInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\NowTile;
use Uhifadhi\Bundle\AreaBundle\Overview\OrgOverviewContributorInterface;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\Widget;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Model\IncidentOrgReading;
use Uhifadhi\Incident\Overview\IncidentOrgWidgets;
use Uhifadhi\Incident\Service\IncidentOrgFigures;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Incident\UhifadhiIncidentBundle;

/**
 * WHAT THIS MODULE PUTS ON `/` — the organization seam, answered against a
 * real register spread over TWO areas.
 *
 * TWO AREAS, DELIBERATELY. The whole claim of the organization contract is
 * that the wide reading IS the narrow ones: a suite with one area would pass
 * against a contributor that had quietly kept reading a single area and
 * nobody would know.
 *
 * THE MORNING IS SATURDAY 19 SEPTEMBER 2026, 11:42 — the design's own sample
 * instant for the organization dashboard.
 */
final class IncidentOrgContributionTest extends IntegrationTestCase
{
    private const string NOW = '2026-09-19 11:42:00';

    public function testTheContributorAnswersForThisModulesOwnSlugAndItsOwnTemplates(): void
    {
        $contributor = $this->contributor();

        self::assertInstanceOf(OrgOverviewContributorInterface::class, $contributor);
        // The same string the area contributor and the module provider declare.
        self::assertSame('incidents', $contributor->moduleSlug());
        self::assertSame('incidents', $contributor->group()->id);
        self::assertSame('@UhifadhiIncident/org/_w_%s.html.twig', $contributor->partialPattern());

        // THE CELL THE SHIPPED COMPOSITIONS NAME. Three of the five presets the
        // core ships lay out an `incidents` cell; a different id here and the
        // module would contribute a cell no design has a place for.
        self::assertSame(
            ['incidents'],
            array_map(static fn (Widget $widget) => $widget->id, $contributor->widgets()),
        );

        // The cell is drawn inside the host's page, so the sheet its chips and
        // states come from has to travel with it.
        self::assertInstanceOf(ContributesStylesheetInterface::class, $contributor);
        self::assertSame(UhifadhiIncidentBundle::STYLESHEET, $contributor->stylesheet());
    }

    /**
     * THE FIGURE IS THE AREAS' OWN FIGURES ADDED UP, and it is proved by
     * asking the SAME contributor at each narrower scope and summing what it
     * says — not by asserting a number a fixture happened to produce.
     */
    public function testTheOrganizationFigureIsEveryAreasFigureOneScopeWider(): void
    {
        [$north, $south] = $this->twoAreasWithOpenWork();

        $wide = $this->tile(Scope::organization());
        $narrow = array_map(
            fn (AreaOfInterest $area) => (int) $this->tile(Scope::area((string) $area->getUuidString(), (string) $area->getName()))->value,
            [$north, $south],
        );

        self::assertSame(array_sum($narrow), (int) $wide->value);
        self::assertSame(5, (int) $wide->value);
        self::assertSame('Open incidents', $wide->label);
        self::assertSame('IN·G1', $wide->index);
        self::assertSame('incidents', $wide->moduleSlug);
    }

    /**
     * The tile states what it is made of, and raises the ONE alarm this module
     * raises: work past the term it was filed under.
     */
    public function testTheFigureSaysWhatItIsMadeOfAndFlagsTheBrokenPromises(): void
    {
        $this->twoAreasWithOpenWork();

        $tile = $this->tile(Scope::organization());

        self::assertSame('1 filed today', $tile->subline);
        self::assertSame('2 past their term', $tile->alarm);
        // The plate stays plain: a backlog is not an alarm, and the alarm
        // carries the failure colour on its own.
        self::assertSame(NowTile::TONE_PLAIN, $tile->tone);
    }

    /**
     * THE FIGURE IS THIRD ON THE STRIP, which is the design's order: on duty,
     * patrols out, open incidents, files kept.
     *
     * The neighbours' published priorities are spelt out rather than imported
     * — neither module is a dependency of this one, and a constant borrowed
     * from a package that may not be installed would only pretend to be the
     * same number. The bound is what matters, so the bound is what is pinned:
     * after patrols, before files.
     */
    public function testTheFigureSitsBetweenPatrolsAndFilesOnTheStrip(): void
    {
        $this->twoAreasWithOpenWork();

        $priority = $this->tile(Scope::organization())->priority;

        self::assertGreaterThan(30, $priority, 'patrol-module publishes 30 and comes first.');
        self::assertLessThan(40, $priority, 'storage-module publishes 40 and comes last.');
    }

    /**
     * ABSENT IS NOT ZERO. An installation where nothing has ever been filed
     * publishes no figure — the strip draws its own "nothing measured" slot
     * rather than this module claiming it looked.
     */
    public function testAnInstallationWithNoRegisterAnywherePublishesNoFigure(): void
    {
        $this->anAreaWithKinds('an area nobody has filed against');

        self::assertSame([], $this->contributor()->figures(Scope::organization(), $this->now()));
    }

    /** The cell reads its own figures under its own slug, and nothing else. */
    public function testTheCellsContextIsKeyedByTheModulesOwnSlugsReading(): void
    {
        $this->twoAreasWithOpenWork();

        $context = $this->contributor()->context(Scope::organization(), $this->now());

        self::assertSame(['org'], array_keys($context));
        $reading = $context['org'];
        self::assertInstanceOf(IncidentOrgReading::class, $reading);
        self::assertSame(5, $reading->openCount());
        self::assertSame('5 open · 2 past their term', $reading->cardSubline());
    }

    /**
     * THE CELL IS BOUNDED AND THE ROWS ARE THE NEWEST OF WHAT WAS COUNTED —
     * five rows over a set of five here, and the truncation rule is the
     * model's own unit test.
     */
    public function testTheRowsAreTheNewestOpenWorkAndSpanEveryArea(): void
    {
        $this->twoAreasWithOpenWork();

        $reading = $this->reading();
        $references = array_map(static fn (Incident $incident) => $incident->getReference(), $reading->latest());

        self::assertCount(5, $references);
        self::assertSame($references, array_values(array_unique($references)));
        // Newest first, whichever area it was filed in.
        self::assertSame('INC-0001', $references[0]);
        self::assertGreaterThan(1, \count(array_unique(array_map(
            static fn (Incident $incident) => $incident->getArea()->getName(),
            $reading->latest(),
        ))));
    }

    /**
     * THE DOOR IS HONEST ABOUT WHAT THIS INSTALLATION HAS. Open work in
     * several areas has no one register behind it, so the door goes to the
     * areas register rather than picking an area and calling it everybody's.
     */
    public function testTheDoorLeadsToTheAreasRegisterWhenTheWorkSpansAreas(): void
    {
        $this->twoAreasWithOpenWork();

        $reading = $this->reading();

        self::assertSame('Every area', $reading->doorLabel);
        self::assertSame('/areas', $reading->doorUrl);
    }

    /** One area's worth of open work has one register behind it, and the door goes there. */
    public function testTheDoorLeadsToTheOneRegisterBehindItWhereThereIsOne(): void
    {
        $area = $this->anAreaWithKinds('the only area filing');
        $this->anIncident($area, at: new \DateTimeImmutable('2026-09-18 08:12:00'));
        $this->em->flush();
        $this->em->clear();

        $reading = $this->reading();

        self::assertSame('Incidents', $reading->doorLabel);
        self::assertStringEndsWith('/modules/incidents', (string) $reading->doorUrl);
    }

    /**
     * Two areas, five incidents still open between them, two of them past the
     * term their own sub-category promised and one filed this morning.
     *
     * @return array{AreaOfInterest, AreaOfInterest}
     */
    private function twoAreasWithOpenWork(): array
    {
        $north = $this->anAreaWithKinds('North Range');
        $south = $this->anAreaWithKinds('South Range');

        // Filed this morning, comfortably inside a 72-hour term.
        $this->anIncident($north, at: new \DateTimeImmutable('2026-09-19 08:12:00'));
        // Yesterday, in the south, still inside its term.
        $this->anIncident($south, at: new \DateTimeImmutable('2026-09-18 09:00:00'));
        // Two days ago, in the north: inside a 30-day claim term.
        $this->anIncident($north, 'crop-raiding', at: new \DateTimeImmutable('2026-09-17 09:00:00'));
        // Three weeks old in each area, and both past their own term.
        $this->anIncident($north, 'snaring', at: new \DateTimeImmutable('2026-08-29 09:00:00'));
        $this->anIncident($south, 'snaring', at: new \DateTimeImmutable('2026-08-31 09:00:00'));

        $this->em->flush();
        // Read back out of the database rather than out of the identity map,
        // so the scope-aware queries are actually exercised.
        $this->em->clear();

        return [$this->reload($north), $this->reload($south)];
    }

    private function reload(AreaOfInterest $area): AreaOfInterest
    {
        $reloaded = $this->em->getRepository(AreaOfInterest::class)->find($area->getId());
        self::assertInstanceOf(AreaOfInterest::class, $reloaded);

        return $reloaded;
    }

    private function tile(Scope $scope): NowTile
    {
        $tiles = $this->contributor()->figures($scope, $this->now());
        self::assertCount(1, $tiles);

        return $tiles[0];
    }

    private function reading(): IncidentOrgReading
    {
        $figures = $this->service('incident.org.figures');
        self::assertInstanceOf(IncidentOrgFigures::class, $figures);

        return $figures->forScope(Scope::organization(), $this->now());
    }

    private function contributor(): IncidentOrgWidgets
    {
        $contributor = $this->service('incident.org.widgets');
        self::assertInstanceOf(IncidentOrgWidgets::class, $contributor);

        return $contributor;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }
}
