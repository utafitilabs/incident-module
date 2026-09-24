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

namespace Uhifadhi\Incident\Module;

use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Performance\ChartKind;
use Uhifadhi\Contracts\Performance\ChartSeries;
use Uhifadhi\Contracts\Performance\ColumnPolarity;
use Uhifadhi\Contracts\Performance\DepartmentDirectoryInterface;
use Uhifadhi\Contracts\Performance\DepartmentEntry;
use Uhifadhi\Contracts\Performance\MatrixCell;
use Uhifadhi\Contracts\Performance\MatrixColumn;
use Uhifadhi\Contracts\Performance\MatrixRow;
use Uhifadhi\Contracts\Performance\PerformanceScope;
use Uhifadhi\Contracts\Performance\PerformanceTopicProviderInterface;
use Uhifadhi\Contracts\Performance\TopicChart;
use Uhifadhi\Contracts\Performance\TopicKpi;
use Uhifadhi\Contracts\Performance\TopicMatrix;
use Uhifadhi\Incident\Access\IncidentConcerns;
use Uhifadhi\Incident\Access\IncidentDoors;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Model\IncidentReading;
use Uhifadhi\Incident\Model\IncidentTopicGround;
use Uhifadhi\Incident\Model\IncidentTopicSlice;
use Uhifadhi\Incident\Model\PerformanceReadings;
use Uhifadhi\Incident\Repository\IncidentRepository;

/**
 * THE INCIDENTS TOPIC ON THE PERFORMANCE PAGE — five figures, two charts and
 * the departments that read this module.
 *
 * A TOPIC, NOT A COLUMN. The board this replaces put one module's columns
 * against every department, and half the cells were about departments that had
 * never attached the module. This publishes its own section instead: what was
 * filed, what is overdue, how long closing takes, what compensation is waiting
 * and what got finished — and a matrix whose ROWS ARE ONLY THE DEPARTMENTS
 * THAT ATTACH INCIDENTS. A department that does not read this module is not a
 * row of dashes here; it is not a row.
 *
 * ── FIGURES FOLLOW SCOPE, NOT PEOPLE ─────────────────────────────────────────
 * A DEPARTMENT IS A LENS OVER GROUND, NEVER A FILTER ON RECORDS. Who filed an
 * incident, whether they hold a position and which department that position
 * sits in change no figure on this page — exactly as
 * {@see IncidentDepartmentKpiProvider} states for the KPI plates. All a
 * department contributes to a row is WHICH GROUND it reads, which
 * {@see IncidentTopicSlice} resolves as the intersection of its scope with the
 * page's: an organization-wide department reads every area, an area-level one
 * reads its own. Two departments scoped to the same area therefore read
 * identical figures, and no surface adds them together.
 *
 * ── WHO THE ROWS ARE IS ASKED, NOT QUERIED ───────────────────────────────────
 * {@see DepartmentDirectoryInterface} answers it in ONE READ: who the
 * departments are, what each is placed among, what each attaches, and since
 * when this module has been running somewhere each can see it. This module
 * reads no department table and no area x module ledger — those live in two
 * packages it does not depend on, and the host joins them once so that every
 * topic joins them the same way.
 *
 * ── WHERE THE HISTORY COMES FROM ─────────────────────────────────────────────
 * OUT OF THIS MODULE'S OWN RECORDS, period by period — never out of a
 * remembered figure. The host's own topics read what was written down in a
 * closed period because seats and goals cannot be recomputed; an incident can:
 * it carries when it was filed, when it was finished and what its sub-category
 * promised, so every period behind a sparkline or a chart is re-measured from
 * the rows themselves and gives the same answer today as it did then. Nothing
 * here writes a figure down, and nothing here can go stale.
 *
 * ── THE THREE ABSENCES, KEPT APART ───────────────────────────────────────────
 *  - A HOLE IN A HISTORY is a period this module was not yet installed over
 *    that ground: nobody was recording, and a nought there would draw a
 *    collapse where there was simply no module.
 *  - A NULL VALUE is a figure that cannot be computed — a median time to close
 *    over a period where nothing was finished is not nought days, and every
 *    figure of a scope no area of which runs this module is unknown rather
 *    than empty.
 *  - {@see MatrixCell::notMine()} is a department that attaches Incidents
 *    while NO AREA IT READS ACTUALLY RUNS IT —
 *    {@see DepartmentEntry::canAnswerFor()} is the whole test. It IS a row,
 *    drawn as four dashes: "you attached this and nothing on your ground runs
 *    it" is a fact a director should see, and dropping the department would
 *    hide it. The columns were never put to it, so nought would be a lie
 *    about a question nobody asked.
 *
 * ── POLARITY ─────────────────────────────────────────────────────────────────
 * FILING IS NEITHER GOOD NOR BAD and says so ({@see ColumnPolarity::None}): an
 * area with more incidents filed may simply be an area where people are
 * reporting, which is the behaviour this module exists to encourage, and
 * tinting a department for it would teach exactly the wrong lesson. The same
 * goes for how many compensation claims arrived — that is how many people
 * asked, not how well anybody worked. Overdue work and a slower close are
 * {@see ColumnPolarity::Down}; finished work is {@see ColumnPolarity::Up}.
 */
final readonly class IncidentPerformanceTopic implements PerformanceTopicProviderInterface
{
    /** The topic's own address, in the URL and in the page's order. */
    public const string KEY = 'incidents';

    /** What a sparkline and a matrix cell's run are drawn over. */
    public const int PERIODS = 6;

    /** What the charts are drawn over — a full year of the page's period. */
    public const int CHART_PERIODS = 12;

    public const string FILED = 'incidents.filed';
    public const string OPEN_PAST_TARGET = 'incidents.open_past_target';
    public const string MEDIAN_DAYS_TO_CLOSE = 'incidents.median_days_to_close';
    public const string COMPENSATION_CLAIMS = 'incidents.compensation_claims';
    public const string CLAIMS_OPEN = 'incidents.claims_open';
    public const string RESOLVED = 'incidents.resolved';

    /** The four buckets the backlog chart draws. */
    private const array AGE_LABELS = ['0–7 d', '8–14 d', '15–21 d', 'over 21 d'];

    public function __construct(
        private DepartmentDirectoryInterface $directory,
        private IncidentRepository $incidents,
        /**
         * WHETHER THE COMPENSATION COLUMN MAY BE DRAWN for this reader, over
         * the scope the page is on. Claims are money, and money is a concern
         * of its own.
         */
        private IncidentDoors $doors,
        /** The slug this module is registered under in the registry's catalogue. */
        private string $slug,
        private string $name = 'Incidents',
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return $this->name;
    }

    /**
     * THE FOUR COLUMNS AND THEIR DIRECTIONS, stated once and statically so a
     * test can read them without a database: a column's polarity is a property
     * of the figure, not of a request.
     *
     * @return list<MatrixColumn>
     */
    public static function columns(bool $withMoney = true): array
    {
        $columns = [
            new MatrixColumn(
                self::FILED,
                'Filed',
                polarity: ColumnPolarity::None,
                caption: 'Incidents recorded on this ground in the period. Filing is neither an achievement nor a failure, so this is never tinted.',
            ),
            new MatrixColumn(
                self::OPEN_PAST_TARGET,
                'Open past target',
                polarity: ColumnPolarity::Down,
                caption: 'Still open, and already past what their sub-category promised.',
            ),
            new MatrixColumn(
                self::MEDIAN_DAYS_TO_CLOSE,
                'Median days to close',
                unit: 'd',
                polarity: ColumnPolarity::Down,
                caption: 'The middle time from filing to resolution, over the work finished in the period.',
            ),
        ];

        if ($withMoney) {
            $columns[] = new MatrixColumn(
                self::COMPENSATION_CLAIMS,
                'Compensation claims',
                polarity: ColumnPolarity::None,
                caption: 'Claims that arrived in the period. How many people asked is not a score, so this is never tinted.',
            );
        }

        return $columns;
    }

    /** Whether the money on a case may be read across the page's whole ground. */
    private function seesMoney(PerformanceScope $scope): bool
    {
        return $this->doors->opensAcross(IncidentConcerns::CASE_MONEY, Verb::Read, $scope->areaUuid);
    }

    /**
     * FOUR, AND ALWAYS FOUR — filed, open past target, the median time to
     * close, and what was finished.
     *
     * RULED 2026-09-21: a figure row is four to a row everywhere in the
     * product, so `Claims open` went. It is the one of the five a reader
     * acts on least like the others — the other four are the flow of work
     * through the module, and a compensation claim is a different
     * conversation with a different person. It is still on the matrix, where
     * a department is compared with a department, and still on the module's
     * own pages.
     *
     * @return list<TopicKpi>
     */
    public function kpis(PerformanceScope $scope, FigurePeriod $period): array
    {
        $rows = $this->directory->forScope($scope)->answeringFor($this->slug);
        if ([] === $rows) {
            return $this->nothingRunsHere($scope);
        }

        $run = $this->readingsOver($this->pageGround($scope, $rows), self::run($period, self::PERIODS));

        return [
            self::figure($run, self::FILED, 'Filed', ColumnPolarity::None,
                static fn (array $p): float => (float) $p['filed']->filed(),
                caption: \sprintf('across %d department%s that read %s', \count($rows), 1 === \count($rows) ? '' : 's', $this->name),
            ),
            self::figure($run, self::OPEN_PAST_TARGET, 'Open', ColumnPolarity::Down,
                static fn (array $p): float => (float) $p['filed']->openPastTarget($p['period']->until),
                caption: 'past their target',
            ),
            self::figure($run, self::MEDIAN_DAYS_TO_CLOSE, 'Median days to close', ColumnPolarity::Down,
                static fn (array $p): ?float => $p['resolved']->medianDaysToClose(),
                caption: self::targetsCaption($run[self::PERIODS - 1]['resolved']),
                unit: 'd',
            ),
            self::figure($run, self::RESOLVED, 'Resolved', ColumnPolarity::Up,
                static fn (array $p): float => (float) $p['resolved']->filed(),
                caption: 'this period',
            ),
        ];
    }

    /**
     * TWO CHARTS, both stated as shapes: the run of filing against closing
     * over A FULL YEAR of the page's period, and how long the work still open
     * has been open.
     *
     * THE CHARTS RUN LONGER THAN THE SPARKLINES ON PURPOSE. A card's figure
     * shows its own recent movement; a chart is where a season is supposed to
     * become visible, and six points cannot show one.
     *
     * @return list<TopicChart>
     */
    public function charts(PerformanceScope $scope, FigurePeriod $period): array
    {
        $ground = $this->pageGround($scope, $this->directory->forScope($scope)->answeringFor($this->slug));
        $periods = self::run($period, self::CHART_PERIODS);
        $run = $this->readingsOver($ground, $periods);

        $flow = new TopicChart(
            key: 'incidents.flow',
            title: 'Filed against closed, per period',
            kind: ChartKind::Line,
            labels: array_map(static fn (FigurePeriod $past): string => mb_strtolower($past->from->format('M')), $periods),
            series: [
                new ChartSeries('Filed', self::series($run, static fn (array $p): float => (float) $p['filed']->filed())),
                new ChartSeries('Closed', self::series($run, static fn (array $p): float => (float) $p['resolved']->filed())),
            ],
            caption: 'Re-measured from the records of each period — a period before this module was running is a gap, not a nought.',
        );

        $open = $this->readingsOf($this->incidents->findOpenByScope($ground->areaUuid));
        $buckets = $open->openAgeBuckets($period->until);

        $age = new TopicChart(
            key: 'incidents.age',
            title: 'How long an open incident has been open',
            kind: ChartKind::Bar,
            labels: self::AGE_LABELS,
            // NOTHING OPEN IS NOT FOUR NOUGHTS. A backlog nobody has is an
            // absence of bars, and the chart drops itself rather than drawing
            // an empty floor that reads as a measured one.
            series: [new ChartSeries(
                'Open incidents',
                $open->isEmpty()
                    ? [null, null, null, null]
                    : array_map(static fn (int $n): float => (float) $n, $buckets),
            )],
            unit: 'incidents',
            caption: 'The backlog as it stands, whenever each one was filed.',
        );

        return [$flow, $age];
    }

    /**
     * THE MATRIX, AND ITS MONEY COLUMN IS NOT ALWAYS THERE.
     *
     * `Compensation claims` counts what people asked this department for,
     * which is a money fact and belongs to `case-money`. Somebody who may not
     * read the money gets a matrix WITHOUT that column rather than a column of
     * noughts — a nought is a measurement, and a dash means "never asked",
     * which would be a lie about a figure that was measured and withheld.
     *
     * A COLUMN IS DROPPED RATHER THAN MARKED because a cell has no way to say
     * "withheld": {@see MatrixCell} offers a value, a dash and "not this
     * department's to answer", and borrowing any of the three would print a
     * different sentence from the true one. The column's absence says it
     * honestly, and the topic's caption on the page says the rest.
     */
    public function matrix(PerformanceScope $scope, FigurePeriod $period): TopicMatrix
    {
        $periods = self::run($period, self::PERIODS);
        $seesMoney = $this->seesMoney($scope);

        $rows = [];
        foreach ($this->rowsIn($scope) as $entry) {
            $cells = $this->cellsFor($entry, $scope, $periods);
            if (!$seesMoney) {
                unset($cells[self::COMPENSATION_CLAIMS]);
            }

            $rows[] = new MatrixRow(
                departmentUuid: $entry->uuid,
                departmentName: $entry->name,
                cells: $cells,
                band: $entry->band,
                mark: $entry->mark,
            );
        }

        return new TopicMatrix(
            self::columns($seesMoney),
            $rows,
            \sprintf('Only the departments that read the %s module are rows, and each reads the ground its own scope covers.', $this->name),
        );
    }

    /**
     * ONE DEPARTMENT'S FOUR CELLS.
     *
     * A department that cannot be asked about this module gets four `notMine`
     * cells — dashes, never noughts: it attached Incidents in the register,
     * but no area it reads is running it, so the columns were never put to
     * it.
     *
     * @param list<FigurePeriod> $periods
     *
     * @return array<string, MatrixCell>
     */
    private function cellsFor(DepartmentEntry $entry, PerformanceScope $scope, array $periods): array
    {
        if (!$entry->canAnswerFor($this->slug)) {
            return self::notMineCells();
        }

        $slice = IncidentTopicSlice::of($scope->areaUuid, $entry->areaUuid);
        \assert(null !== $slice);

        $run = $this->readingsOver(
            new IncidentTopicGround($slice->areaUuid, $entry->runningSince[$this->slug] ?? null),
            $periods,
        );

        return [
            self::FILED => self::cell($run, static fn (array $p): float => (float) $p['filed']->filed()),
            self::OPEN_PAST_TARGET => self::cell($run, static fn (array $p): float => (float) $p['filed']->openPastTarget($p['period']->until)),
            self::MEDIAN_DAYS_TO_CLOSE => self::cell($run, static fn (array $p): ?float => $p['resolved']->medianDaysToClose()),
            self::COMPENSATION_CLAIMS => self::cell($run, static fn (array $p): float => (float) $p['filed']->claimsFiled()),
        ];
    }

    /**
     * THE ROW OF A DEPARTMENT NOBODY ASKED — one dash per column.
     *
     * @return array<string, MatrixCell>
     */
    public static function notMineCells(): array
    {
        $cells = [];
        foreach (self::columns() as $column) {
            $cells[$column->key] = MatrixCell::notMine();
        }

        return $cells;
    }

    /**
     * THE ROWS OF THIS MATRIX: every department in the scope that ATTACHES
     * Incidents — including the ones nothing on their ground runs it for.
     *
     * ATTACHING IS WHAT MAKES A ROW, NOT BEING ANSWERABLE. "You attached this
     * module and nothing on your ground is running it" is a fact a director
     * needs to see, and a department quietly dropped from the table is a fact
     * nobody sees. Such a row is four dashes — {@see MatrixCell::notMine()},
     * never noughts — so it reads as "never asked" rather than "answered
     * nothing". A department that attaches nothing of this module's is a
     * different case entirely and is no row at all.
     *
     * @return list<DepartmentEntry>
     */
    private function rowsIn(PerformanceScope $scope): array
    {
        return array_values(array_filter(
            $this->directory->forScope($scope)->entries,
            fn (DepartmentEntry $entry): bool => $entry->attaches($this->slug),
        ));
    }

    /**
     * THE GROUND THE PAGE'S OWN FIGURES ARE READ OVER, and since when.
     *
     * The area is the page's — every area on the organization's page, one on
     * an area's. The date is THE EARLIEST any of the rows could have been
     * asked: the module has been recording somewhere on this page since then,
     * and every period before it is a hole for the headline exactly as it is
     * for the row that dates it.
     *
     * @param list<DepartmentEntry> $rows the departments that can be asked about this module
     */
    private function pageGround(PerformanceScope $scope, array $rows): IncidentTopicGround
    {
        $earliest = null;
        foreach ($rows as $entry) {
            $since = $entry->runningSince[$this->slug] ?? null;
            if (null === $since) {
                continue;
            }

            $earliest = null === $earliest || $since < $earliest ? $since : $earliest;
        }

        return new IncidentTopicGround($scope->areaUuid, $earliest);
    }

    /**
     * WHAT THE GROUND HELD IN EACH OF A RUN OF PERIODS.
     *
     * TWO SETS PER PERIOD, because what was FILED in a period and what was
     * FINISHED in it are different questions, and a page that derived one from
     * the other could only report the overlap. A period before the module was
     * running over the ground is not asked at all: it is a hole.
     *
     * @param list<FigurePeriod> $periods
     *
     * @return list<array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, measured: bool}>
     */
    private function readingsOver(IncidentTopicGround $ground, array $periods): array
    {
        $run = [];
        foreach ($periods as $window) {
            $measured = $ground->measured($window);

            $run[] = [
                'period' => $window,
                'filed' => $measured
                    ? $this->readingsOf($this->incidents->findByScopeBetween($ground->areaUuid, $window->from, $window->until))
                    : PerformanceReadings::none(),
                'resolved' => $measured
                    ? $this->readingsOf($this->incidents->findResolvedByScopeBetween($ground->areaUuid, $window->from, $window->until))
                    : PerformanceReadings::none(),
                'measured' => $measured,
            ];
        }

        return $run;
    }

    /**
     * THE ROWS, REDUCED TO WHAT A FIGURE IS MADE OF. Nobody is on a reading:
     * figures follow scope, not people.
     *
     * @param list<Incident> $incidents
     */
    private function readingsOf(array $incidents): PerformanceReadings
    {
        $readings = [];
        foreach ($incidents as $incident) {
            $subcategory = $incident->getSubcategory();
            // A CLAIM IS FILED BY THE WORDS, NOT BY THE MONEY CARD. A
            // compensation-bearing sub-category means somebody has asked; the
            // money row is what the assessment writes afterwards, and a claim
            // nobody has costed yet is still a claim.
            $claimed = MoneyDirectionEnum::Compensation === $subcategory->getMoneyDirection();
            $money = $incident->getMoney();

            $readings[] = new IncidentReading(
                reportedAt: $incident->getReportedAt(),
                resolvedAt: $incident->getResolvedAt(),
                termHours: $subcategory->getTermHours(),
                open: $incident->getStatus()->isOpen(),
                claimed: $claimed,
                claimOutstanding: $claimed && (null === $money || (!$money->isSettled() && !$money->isWaived())),
            );
        }

        return new PerformanceReadings($readings);
    }

    /**
     * FIVE FIGURES THAT SAY THEY HAVE NOTHING, for a scope where no department
     * can be asked about this module at all. A row of none where the page
     * draws five is a different page, and a reader cannot tell a missing topic
     * from a quiet month.
     *
     * @return list<TopicKpi>
     */
    private function nothingRunsHere(PerformanceScope $scope): array
    {
        $why = \sprintf('no department of %s can be asked about the %s module', mb_strtolower($scope->label), $this->name);

        return [
            new TopicKpi(self::FILED, 'Filed', null, caption: $why, polarity: ColumnPolarity::None),
            new TopicKpi(self::OPEN_PAST_TARGET, 'Open', null, caption: $why, polarity: ColumnPolarity::Down),
            new TopicKpi(self::MEDIAN_DAYS_TO_CLOSE, 'Median days to close', null, 'd', caption: $why, polarity: ColumnPolarity::Down),
            new TopicKpi(self::RESOLVED, 'Resolved', null, caption: $why, polarity: ColumnPolarity::Up),
        ];
    }

    /**
     * ONE HEADLINE FIGURE, with its movement and its run — the value read out
     * of the history's last point rather than computed a second time, so a
     * period the module was not running in reads "no figure" on the card and
     * as a hole in the sparkline instead of disagreeing with itself.
     *
     * @param list<array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, measured: bool}>             $run
     * @param callable(array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, measured: bool}): ?float $reading
     */
    private static function figure(
        array $run,
        string $key,
        string $label,
        ColumnPolarity $polarity,
        callable $reading,
        string $caption = '',
        string $unit = '',
    ): TopicKpi {
        $history = self::series($run, $reading);

        return new TopicKpi(
            key: $key,
            label: $label,
            value: $history[\count($history) - 1],
            unit: $unit,
            delta: self::delta($run, $reading),
            history: $history,
            caption: $caption,
            polarity: $polarity,
        );
    }

    /**
     * ONE CELL, with its movement and its run.
     *
     * @param list<array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, measured: bool}>             $run
     * @param callable(array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, measured: bool}): ?float $reading
     */
    private static function cell(array $run, callable $reading): MatrixCell
    {
        $history = self::series($run, $reading);

        return new MatrixCell(
            value: $history[\count($history) - 1],
            delta: self::delta($run, $reading),
            history: $history,
        );
    }

    /**
     * THE POINTS, OLDEST FIRST, HOLES KEPT. A period before this module was
     * installed over the ground is null — nobody was recording, which is not
     * the same fact as recording nothing.
     *
     * @param list<array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, measured: bool}>             $run
     * @param callable(array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, measured: bool}): ?float $reading
     *
     * @return list<float|null>
     */
    private static function series(array $run, callable $reading): array
    {
        $points = [];
        foreach ($run as $snapshot) {
            $points[] = $snapshot['measured'] ? $reading($snapshot) : null;
        }

        return $points;
    }

    /**
     * THE MOVEMENT ON THE PERIOD BEFORE, and null where either end of the
     * comparison has no figure — "it moved" is a claim, and a claim needs two
     * readings.
     *
     * @param list<array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, measured: bool}>             $run
     * @param callable(array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, measured: bool}): ?float $reading
     */
    private static function delta(array $run, callable $reading): ?float
    {
        $history = self::series($run, $reading);
        $last = \count($history) - 1;
        $now = $history[$last];
        $was = $history[$last - 1] ?? null;

        return null === $now || null === $was ? null : $now - $was;
    }

    /**
     * THE RUN A FIGURE IS DRAWN OVER — so many periods ending at this one,
     * oldest first, each the same length as the one the page asked for.
     *
     * Stepped back through {@see FigurePeriod::previous()} rather than assumed
     * to be months, so a page reading quarters gets quarters.
     *
     * @return list<FigurePeriod>
     */
    private static function run(FigurePeriod $period, int $count): array
    {
        $run = [$period];
        for ($step = 1; $step < $count; ++$step) {
            array_unshift($run, $run[0]->previous());
        }

        return $run;
    }

    /**
     * WHAT THE MEDIAN WAS PROMISED AGAINST. A duration printed without its
     * term is unreadable, and the term is the sub-category's rather than a
     * setting anybody could name here.
     */
    private static function targetsCaption(PerformanceReadings $resolved): string
    {
        $terms = $resolved->termsInDays();
        if ([] === $terms) {
            return 'nothing finished in this period';
        }

        return \sprintf(
            'targets %s',
            implode(' and ', array_map(
                static fn (float $days): string => rtrim(rtrim(number_format($days, 1, '.', ''), '0'), '.'),
                $terms,
            )),
        );
    }
}
