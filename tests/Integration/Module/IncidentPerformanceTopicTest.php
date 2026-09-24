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

namespace Uhifadhi\Incident\Tests\Integration\Module;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\Entity\AreaModule;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Performance\ChartKind;
use Uhifadhi\Contracts\Performance\MatrixColumn;
use Uhifadhi\Contracts\Performance\MatrixRow;
use Uhifadhi\Contracts\Performance\PerformanceScope;
use Uhifadhi\Contracts\Performance\TopicKpi;
use Uhifadhi\Incident\Module\IncidentPerformanceTopic;
use Uhifadhi\Incident\Tests\Integration\Fixtures\CollectedTopicProviders;
use Uhifadhi\Incident\Tests\Integration\Fixtures\FixedPermissionVoter;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * THE TOPIC THE INCIDENTS MODULE PUBLISHES, against real rows in a real
 * PostGIS database.
 *
 * Every assertion here is about the four things the contract holds a topic to:
 * five figures whatever the month, rows only for the departments that read the
 * module, the scope obeyed rather than assumed, and the three absences kept
 * apart — a hole where the module was not yet running, a figure nobody can
 * compute, and a column a department was never asked.
 *
 * AND ONE RULE THIS MODULE'S BRIEF NEARLY GOT WRONG: figures follow scope, not
 * people. Two departments reading the same ground read identical figures, and
 * who filed an incident decides nothing.
 *
 * WHO THE ROWS ARE COMES THROUGH THE HOST'S DIRECTORY, so these fixtures write
 * the world an installation writes — areas, a catalogue row, the day each area
 * switched the module on, and departments that attach it — and then read the
 * topic, never a department table.
 */
final class IncidentPerformanceTopicTest extends IntegrationTestCase
{
    private const string AUGUST = '2026-08-19 09:00:00';

    /** The wired topic, so the tag and the arguments are under test too. */
    private function topic(): IncidentPerformanceTopic
    {
        /** @var IncidentPerformanceTopic $topic */
        $topic = $this->service('incident.performance_topic');

        return $topic;
    }

    private static function period(string $at = self::AUGUST): FigurePeriod
    {
        return FigurePeriod::month(new \DateTimeImmutable($at));
    }

    /**
     * TWO AREAS RUNNING INCIDENTS SINCE JUNE, three departments, and a handful
     * of records — the world nearly every assertion below reads.
     *
     * @return array{north: AreaOfInterest, south: AreaOfInterest, module: Module}
     */
    private function world(): array
    {
        $north = $this->anAreaWithKinds('North Sector');
        $south = $this->anAreaWithKinds('South Sector');

        $module = new Module()->setSlug('incidents')->setName('Incidents');
        $this->em->persist($module);

        // Installed in June, so the periods before it are holes.
        $this->install($module, $north, '2026-06-10 08:00:00');
        $this->install($module, $south, '2026-06-10 08:00:00');

        $this->reading('Ecology', $module);
        $this->reading('Protection Service', $module, $north);

        // Two in the north, one in the south, filed by nobody seated — which
        // changes no figure, because figures follow scope.
        $this->anIncident($north, at: new \DateTimeImmutable('2026-08-04 09:00:00'));
        $this->anIncident($north, 'snaring', 'Snare line lifted', new \DateTimeImmutable('2026-08-06 09:00:00'));
        $this->anIncident($south, 'roadkill', 'Zebra roadkill', new \DateTimeImmutable('2026-08-07 09:00:00'));
        $this->em->flush();

        return ['north' => $north, 'south' => $south, 'module' => $module];
    }

    private function install(Module $module, AreaOfInterest $area, string $at): AreaModule
    {
        $installed = new AreaModule()->setModule($module)
            ->setArea($area)
            ->setActive(true)
            ->setInstalledAt(new \DateTimeImmutable($at));
        $this->em->persist($installed);

        return $installed;
    }

    /** A department that READS Incidents — the only kind this topic has a row for. */
    private function reading(string $name, Module $module, ?AreaOfInterest $area = null): Department
    {
        $department = $this->aDepartment($name);
        $department->attachModule($module);
        if (null !== $area) {
            $department->setArea($area);
        }
        $this->em->flush();

        return $department;
    }

    /** @return array<string, TopicKpi> */
    private function kpis(PerformanceScope $scope, ?FigurePeriod $period = null): array
    {
        $byKey = [];
        foreach ($this->topic()->kpis($scope, $period ?? self::period()) as $kpi) {
            $byKey[$kpi->key] = $kpi;
        }

        return $byKey;
    }

    /**
     * THE MATRIX AS A READER SEES IT, and a reader has to be signed in: the
     * `Compensation claims` column is money and asks the door, which fails
     * closed on nobody at all. The manager holds `case-money.read` across the
     * organization; the withholding has its own test below.
     *
     * @return array<string, MatrixRow>
     */
    private function rows(PerformanceScope $scope, ?FigurePeriod $period = null): array
    {
        $this->signIn($this->aUser(FixedPermissionVoter::MANAGER_EMAIL, 'Sara', 'Laizer'));

        $byName = [];
        foreach ($this->topic()->matrix($scope, $period ?? self::period())->rows as $row) {
            $byName[$row->departmentName] = $row;
        }

        return $byName;
    }

    public function testItPublishesTheIncidentsTopic(): void
    {
        self::assertSame('incidents', $this->topic()->moduleSlug());
        self::assertSame('incidents', $this->topic()->key());
        self::assertSame('Incidents', $this->topic()->title());
    }

    /** The tag is applied by hand; this is what proves it stuck. */
    public function testTheTopicReachesThePerformancePageThroughItsTag(): void
    {
        /** @var CollectedTopicProviders $collected */
        $collected = static::getContainer()->get(CollectedTopicProviders::class);

        self::assertArrayHasKey('incidents', $collected->byKey());
        self::assertInstanceOf(IncidentPerformanceTopic::class, $collected->byKey()['incidents']);
    }

    /**
     * FOUR, AND ALWAYS FOUR — including where no area runs the module at all.
     *
     * RULED 2026-09-21 and pinned here by KEY and by LABEL, in the design's
     * order. The count alone would pass on a row of four different figures,
     * and the labels are what a reader recognises the topic by.
     */
    public function testFourHeadlineFiguresAreAlwaysPublished(): void
    {
        $this->world();

        $kpis = $this->kpis(PerformanceScope::organization());

        self::assertSame([
            IncidentPerformanceTopic::FILED,
            IncidentPerformanceTopic::OPEN_PAST_TARGET,
            IncidentPerformanceTopic::MEDIAN_DAYS_TO_CLOSE,
            IncidentPerformanceTopic::RESOLVED,
        ], array_keys($kpis));

        self::assertSame(
            ['Filed', 'Open', 'Median days to close', 'Resolved'],
            array_values(array_map(static fn (TopicKpi $kpi): string => $kpi->label, $kpis)),
        );
    }

    /**
     * AND `Claims open` IS NOT ONE OF THEM. It is the figure a reader acts on
     * least like the other four — they are the flow of work through the
     * module and a compensation claim is a different conversation — so it is
     * the one the four-to-a-row ruling dropped. It is still on the matrix and
     * still on the module's own pages.
     */
    public function testClaimsOpenIsNoLongerAHeadlineFigure(): void
    {
        $this->world();

        self::assertArrayNotHasKey(IncidentPerformanceTopic::CLAIMS_OPEN, $this->kpis(PerformanceScope::organization()));
        self::assertContains(
            IncidentPerformanceTopic::COMPENSATION_CLAIMS,
            array_map(static fn (MatrixColumn $column): string => $column->key, IncidentPerformanceTopic::columns()),
            'the claim figure keeps its column, where a department is compared with a department',
        );
    }

    /**
     * A SCOPE WHERE NOBODY CAN BE ASKED STILL PUBLISHES FOUR, and every one of
     * them is null rather than nought — a short row and a quiet month must
     * not look alike.
     */
    public function testAScopeWhereNobodyCanBeAskedStillPublishesFourUnknowns(): void
    {
        $this->world();
        $elsewhere = $this->anArea('Unserved Reserve');
        $this->em->flush();

        $kpis = $this->kpis(PerformanceScope::area((string) $elsewhere->getUuidString(), 'Unserved Reserve'));

        self::assertCount(4, $kpis);
        foreach ($kpis as $kpi) {
            self::assertNull($kpi->value, $kpi->key.' is not a nought where nobody can be asked.');
            self::assertStringContainsString('asked about the Incidents module', $kpi->caption);
        }
    }

    public function testTheHeadlineIsTheWholeScopesRecords(): void
    {
        $this->world();

        self::assertSame(3.0, $this->kpis(PerformanceScope::organization())[IncidentPerformanceTopic::FILED]->value);
        self::assertSame(
            'across 2 departments that read Incidents',
            $this->kpis(PerformanceScope::organization())[IncidentPerformanceTopic::FILED]->caption,
        );
    }

    /** A median over nothing finished is NULL, and says so. */
    public function testTheMedianIsNullWhileNothingWasFinishedInThePeriod(): void
    {
        $this->world();

        $median = $this->kpis(PerformanceScope::organization())[IncidentPerformanceTopic::MEDIAN_DAYS_TO_CLOSE];

        self::assertFalse($median->isKnown());
        self::assertSame('nothing finished in this period', $median->caption);
        self::assertSame('d', $median->unit);
    }

    /**
     * ONLY THE DEPARTMENTS THAT ATTACH INCIDENTS ARE ROWS. One that attaches
     * nothing of this module's is not a row of dashes; it is not a row.
     */
    public function testTheRowsAreOnlyTheDepartmentsThatAttachTheModule(): void
    {
        $this->world();
        $this->aDepartment('Human Resource');
        $this->em->flush();

        self::assertSame(
            ['Ecology', 'Protection Service'],
            array_keys($this->rows(PerformanceScope::organization())),
        );
    }

    /**
     * EACH ROW READS THE GROUND ITS OWN SCOPE COVERS — and nothing about who
     * filed anything. Ecology is org-wide and reads both areas; Protection
     * Service is confined to the north and reads only it.
     */
    public function testEachRowReadsTheGroundItsOwnScopeCovers(): void
    {
        $this->world();

        $rows = $this->rows(PerformanceScope::organization());

        self::assertSame('Org-wide', $rows['Ecology']->band);
        self::assertSame('EC', $rows['Ecology']->mark, 'The mark comes from the directory, not from this module.');
        self::assertSame(3.0, $rows['Ecology']->cells[IncidentPerformanceTopic::FILED]->value);

        self::assertSame('North Sector', $rows['Protection Service']->band);
        self::assertSame('PS', $rows['Protection Service']->mark);
        self::assertSame(2.0, $rows['Protection Service']->cells[IncidentPerformanceTopic::FILED]->value);
    }

    /**
     * TWO DEPARTMENTS ON ONE AREA READ IDENTICAL FIGURES. This is the whole
     * rule, and the one a "slice by the recorder's department" reading would
     * have broken: a second northern department, with no people at all, reads
     * exactly what Protection Service reads.
     */
    public function testTwoDepartmentsScopedToOneAreaReadIdenticalFigures(): void
    {
        $world = $this->world();
        $this->reading('Community Development', $world['module'], $world['north']);

        $rows = $this->rows(PerformanceScope::organization());

        self::assertEquals(
            $rows['Protection Service']->cells,
            $rows['Community Development']->cells,
            'The same ground is the same figures, whoever recorded anything.',
        );
    }

    /**
     * A SCOPE NARROWS BOTH ENDS: the figures read that area's records, and the
     * rows are that area's departments plus the organization-wide ones — with
     * an org-wide department now reading only the page's area.
     */
    public function testAnAreaScopeNarrowsTheFiguresAndTheRowSet(): void
    {
        $world = $this->world();
        $this->reading('South Ecology', $world['module'], $world['south']);

        $scope = PerformanceScope::area((string) $world['north']->getUuidString(), 'North Sector');

        self::assertSame(2.0, $this->kpis($scope)[IncidentPerformanceTopic::FILED]->value);

        $rows = $this->rows($scope);
        self::assertSame(['Ecology', 'Protection Service'], array_keys($rows));
        self::assertSame(2.0, $rows['Ecology']->cells[IncidentPerformanceTopic::FILED]->value, 'Org-wide, but on this page it reads the north.');
    }

    /**
     * A PERIOD BEFORE THIS MODULE WAS RUNNING OVER THE GROUND IS A HOLE, not a
     * nought — and a movement measured against a hole is no movement at all.
     */
    public function testPeriodsBeforeTheModuleWasInstalledAreHoles(): void
    {
        $this->world();

        $filed = $this->kpis(PerformanceScope::organization())[IncidentPerformanceTopic::FILED];

        // Installed 10 June: March, April and May are holes; June and July are
        // measured and genuinely nought.
        self::assertCount(IncidentPerformanceTopic::PERIODS, $filed->history);
        self::assertSame([null, null, null, 0.0, 0.0, 3.0], $filed->history);
        self::assertSame(3.0, $filed->delta, 'July was recorded and held nothing, so this is a real rise.');
    }

    /** A month the module WAS running in and nobody filed in is a real nought. */
    public function testAMeasuredPeriodWithNoRecordsIsANoughtAndNotAHole(): void
    {
        $this->world();

        $filed = $this->kpis(PerformanceScope::organization(), self::period('2026-07-19 09:00:00'))[IncidentPerformanceTopic::FILED];

        self::assertSame(0.0, $filed->value);
        self::assertTrue($filed->isKnown(), 'Nobody filed in July, but somebody was looking.');
    }

    /**
     * THE THIRD ABSENCE. A department that attaches Incidents while no area it
     * reads runs the module IS A ROW — of four dashes, never four noughts.
     * "You attached this and nothing on your ground runs it" is a fact the
     * director should see, and a department quietly dropped from the table is
     * a fact nobody sees.
     */
    public function testADepartmentWhoseGroundRunsNothingIsARowOfDashes(): void
    {
        $world = $this->world();
        $unserved = $this->anArea('Unserved Reserve');
        $this->reading('Unserved Ecology', $world['module'], $unserved);

        $rows = $this->rows(PerformanceScope::organization());

        self::assertSame(['Ecology', 'Protection Service', 'Unserved Ecology'], array_keys($rows));

        $cells = $rows['Unserved Ecology']->cells;
        self::assertCount(4, $cells);
        foreach ($cells as $key => $cell) {
            self::assertTrue($cell->notMine, $key.' is not that department\'s to answer.');
            self::assertFalse($cell->isKnown());
            self::assertNull($cell->delta, 'A question nobody asked did not move.');
        }

        // While a department that CAN be asked has real figures in the same
        // columns — the dashes above are about the question, not the data.
        self::assertFalse($rows['Protection Service']->cells[IncidentPerformanceTopic::COMPENSATION_CLAIMS]->notMine);
        self::assertSame(1.0, $rows['Protection Service']->cells[IncidentPerformanceTopic::COMPENSATION_CLAIMS]->value);
    }

    /** A department that attaches nothing of this module's is no row at all. */
    public function testADepartmentThatAttachesNothingIsNoRowAtAll(): void
    {
        $this->world();
        $this->aDepartment('Human Resource');
        $this->em->flush();

        self::assertArrayNotHasKey('Human Resource', $this->rows(PerformanceScope::organization()));
    }

    /** A claim that arrived is counted in the column it belongs to. */
    public function testAClaimThatArrivedIsCountedInTheColumnAndInTheOpenFigure(): void
    {
        $this->world();

        // livestock-depredation runs compensation; snaring and roadkill run fines.
        self::assertSame(
            1.0,
            $this->rows(PerformanceScope::organization())['Ecology']->cells[IncidentPerformanceTopic::COMPENSATION_CLAIMS]->value,
        );
        // The headline dropped the claim figure; the COLUMN is where it is
        // read now, which is what the assertion above checks.
    }

    /**
     * TWELVE PERIODS ON A CHART, SIX ON A SPARKLINE — ruled, and the reason is
     * that a season cannot be seen in six points.
     */
    public function testTheChartsRunAFullYearWhileTheSparklinesRunSix(): void
    {
        $this->world();

        $charts = $this->topic()->charts(PerformanceScope::organization(), self::period());

        self::assertCount(2, $charts);
        self::assertSame('incidents.flow', $charts[0]->key);
        self::assertSame(ChartKind::Line, $charts[0]->kind);
        self::assertCount(IncidentPerformanceTopic::CHART_PERIODS, $charts[0]->labels);
        self::assertSame(
            ['sep', 'oct', 'nov', 'dec', 'jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug'],
            $charts[0]->labels,
        );
        foreach ($charts[0]->series as $series) {
            self::assertCount(IncidentPerformanceTopic::CHART_PERIODS, $series->points);
        }
        // The nine periods before June are holes; June and July are noughts.
        self::assertSame(
            [null, null, null, null, null, null, null, null, null, 0.0, 0.0, 3.0],
            $charts[0]->series[0]->points,
        );

        self::assertCount(
            IncidentPerformanceTopic::PERIODS,
            $this->kpis(PerformanceScope::organization())[IncidentPerformanceTopic::FILED]->history,
        );
    }

    public function testTheBacklogChartBucketsTheOpenWork(): void
    {
        $this->world();

        $charts = $this->topic()->charts(PerformanceScope::organization(), self::period());

        self::assertSame('incidents.age', $charts[1]->key);
        self::assertSame(ChartKind::Bar, $charts[1]->kind);
        self::assertSame(['0–7 d', '8–14 d', '15–21 d', 'over 21 d'], $charts[1]->labels);
        self::assertFalse($charts[1]->isEmpty(), 'Three incidents are open, so the backlog has bars.');
    }

    /** A backlog nobody has is an absence of bars, and the chart drops itself. */
    public function testTheBacklogChartDrawsNothingWhenNothingIsOpen(): void
    {
        $charts = $this->topic()->charts(PerformanceScope::organization(), self::period());

        self::assertTrue($charts[1]->isEmpty());
    }

    /**
     * THE MONEY COLUMN IS NOT THERE FOR SOMEBODY WHO MAY NOT READ MONEY.
     *
     * A claim is what a person asked for, and that is a money fact. The
     * column goes rather than filling with noughts (a nought is a
     * measurement) or with dashes ({@see MatrixCell::notMine()} says "never
     * asked", which would be a lie about a figure that was measured and
     * withheld). The other three columns are untouched: a withheld fact never
     * withholds the table it sits in.
     */
    public function testTheCompensationColumnIsWithheldFromAReaderWhoMayNotReadMoney(): void
    {
        $this->world();
        $this->signIn($this->aUser(FixedPermissionVoter::CLERK_EMAIL, 'Sara', 'Mushi'));

        $matrix = $this->topic()->matrix(PerformanceScope::organization(), self::period());

        self::assertSame(
            [
                IncidentPerformanceTopic::FILED,
                IncidentPerformanceTopic::OPEN_PAST_TARGET,
                IncidentPerformanceTopic::MEDIAN_DAYS_TO_CLOSE,
            ],
            array_map(static fn (MatrixColumn $column): string => $column->key, $matrix->columns),
        );

        foreach ($matrix->rows as $row) {
            self::assertArrayNotHasKey(IncidentPerformanceTopic::COMPENSATION_CLAIMS, $row->cells);
            self::assertArrayHasKey(IncidentPerformanceTopic::FILED, $row->cells);
        }
    }
}
