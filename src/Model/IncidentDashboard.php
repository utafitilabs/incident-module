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

namespace Uhifadhi\Incident\Model;

use Uhifadhi\Bundle\AtlasBundle\Model\AtlasChart;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;
use Uhifadhi\Contracts\Atlas\CalendarMonth;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\TaxonomyKind;
use Uhifadhi\Incident\Enum\IncidentSeverityEnum;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Storage\Model\FileEntry;

/**
 * EVERYTHING THE SIXTEEN WIDGETS DRAW, computed once.
 *
 * A dashboard renders whichever widgets a person has switched on, and several of
 * them ask the same questions — the KPI strip, the matrix and the funnel all want
 * the counts by status; the register, the map and the feed all want the same
 * rows. Computing per widget would run the same query four times and, worse,
 * would let two widgets on one screen disagree about how many incidents there
 * were, because each would have asked at a slightly different moment.
 *
 * So the surface is resolved ONCE ({@see \Uhifadhi\Incident\Service\IncidentDashboardService})
 * and every partial reads this. It is the same discipline as the design's own
 * rule that ONE filter drives the map, the register and the charts.
 *
 * A Twig partial receives this and reads it; it computes nothing, because a
 * template that does arithmetic is a template nobody can test.
 */
final readonly class IncidentDashboard
{
    /**
     * @param list<TaxonomyKind>                                                                               $kinds          this area's kinds, in its own order
     * @param array<string, int>                                                                               $kindCounts     kind wire-code => count in the window
     * @param array<string, int>                                                                               $statusCounts   status value => count, every place present
     * @param array<string, int>                                                                               $severityCounts severity value => count this window, every level present
     * @param array<string, int>                                                                               $monthlyCounts  'Y-m' => filings that month, six months, oldest first
     * @param array<string, array<string, int>>                                                                $matrix         kind wire-code => status value => count
     * @param array<string, int>                                                                               $zoneCounts     zone name ('' = unzoned) => count
     * @param array<string, int>                                                                               $dailyCounts    Y-m-d => count, every day of the window present
     * @param array<string, array{claimed: int, assessed: int, approved: int, settled: int, outstanding: int}> $money          keyed by {@see MoneyDirectionEnum} value
     * @param list<Incident>                                                                                   $recent         newest first — the register, the feed and the map
     * @param list<Incident>                                                                                   $queue          what is waiting on the signed-in person, oldest first
     * @param list<Incident>                                                                                   $ageing         open work against its own term, worst first
     * @param array<string, list<Incident>>                                                                    $board          status value => the cards in that column
     * @param list<FileEntry>                                                                                  $evidence       newest capture first, as the platform describes a file
     * @param IncidentRail|null                                                                                $rail           the one incident this person last touched, or null
     * @param AtlasMap                                                                                         $map            what every map on this screen draws, stated for the atlas
     * @param CalendarMonth                                                                                    $calendar       the window's filings day by day, stated for the atlas
     */
    public function __construct(
        public IncidentFilter $filter,
        public \DateTimeImmutable $now,
        public array $kinds,
        public int $filedCount,
        public array $kindCounts,
        public array $statusCounts,
        public array $severityCounts,
        public array $monthlyCounts,
        public array $matrix,
        public array $zoneCounts,
        public array $dailyCounts,
        public array $money,
        public array $recent,
        public int $recentTotal,
        public array $queue,
        public array $ageing,
        public array $board,
        public array $evidence,
        public ?float $medianHoursToVerify,
        public int $pastTermCount,
        public ?IncidentRail $rail,
        public string $currency,
        public AtlasMap $map,
        public CalendarMonth $calendar,
    ) {
    }

    /**
     * The five places, in workflow order — so a template iterates the ENUM and
     * never a bare string key. A Twig file that had to name a status by its
     * stored value would be the one place in the module where the workflow's
     * vocabulary is retyped.
     *
     * @return list<IncidentStatusEnum>
     */
    public function places(): array
    {
        return IncidentStatusEnum::ordered();
    }

    /** How many are sitting in one place right now. */
    public function statusCount(IncidentStatusEnum $place): int
    {
        return $this->statusCounts[$place->value] ?? 0;
    }

    /**
     * The cards in one column of the status board.
     *
     * @return list<Incident>
     */
    public function column(IncidentStatusEnum $place): array
    {
        return $this->board[$place->value] ?? [];
    }

    /** How many of one kind reached one place — the matrix's cells. */
    public function matrixCount(TaxonomyKind $kind, IncidentStatusEnum $place): int
    {
        return $this->matrix[$kind->getCode()][$place->value] ?? 0;
    }

    /** How many are still somebody's work — the design's "31 open". */
    public function openCount(): int
    {
        $open = 0;
        foreach (IncidentStatusEnum::ordered() as $place) {
            if ($place->isOpen()) {
                $open += $this->statusCounts[$place->value] ?? 0;
            }
        }

        return $open;
    }

    /**
     * HOW MANY REACHED EACH PLACE — which is not how many are SITTING in it. An
     * incident that is resolved passed through verification, so the funnel's
     * "verified" row counts it; the status board's "verified" column does not.
     * Conflating the two would draw a funnel that widens.
     *
     * @return array<string, int> status value => how many got at least this far
     */
    public function reachedCounts(): array
    {
        $reached = [];
        foreach (IncidentStatusEnum::ordered() as $place) {
            $total = 0;
            foreach ($this->statusCounts as $value => $count) {
                $at = IncidentStatusEnum::tryFrom($value);
                if (null !== $at && $at->hasReached($place)) {
                    $total += $count;
                }
            }
            $reached[$place->value] = $total;
        }

        return $reached;
    }

    /** The money still owed in one direction — the KPI strip adds nothing across the two. */
    public function outstanding(MoneyDirectionEnum $direction): int
    {
        return $this->money[$direction->value]['outstanding'] ?? 0;
    }

    /** The busiest day of the window, for the load bar's caption. Null on an empty month. */
    public function peakDay(): ?string
    {
        if ([] === $this->dailyCounts) {
            return null;
        }

        $peak = array_keys($this->dailyCounts, max($this->dailyCounts), true);

        return $peak[0] ?? null;
    }

    public function peakCount(): int
    {
        return [] === $this->dailyCounts ? 0 : max($this->dailyCounts);
    }

    /**
     * The feed's days, newest first, each with its own count — the design groups
     * the feed by day and prints the day's total beside the heading.
     *
     * An OVERVIEW CARD NEVER GROWS WITH THE DATA: pass a $limit and only the
     * latest that many incidents are grouped, so a busy month does not stretch the
     * feed widget down the page. The widget states "latest N of {recentTotal}"
     * beside its title, the same cap idiom the register uses. Null means the whole
     * window (the widget-library preview, where the card is the surface).
     *
     * @return list<array{day: \DateTimeImmutable, incidents: list<Incident>}>
     */
    public function recentByDay(?int $limit = null): array
    {
        $recent = null === $limit ? $this->recent : \array_slice($this->recent, 0, $limit);

        $days = [];
        foreach ($recent as $incident) {
            $days[$incident->getReportedAt()->format('Y-m-d')][] = $incident;
        }

        $grouped = [];
        foreach ($days as $day => $incidents) {
            $grouped[] = ['day' => new \DateTimeImmutable($day), 'incidents' => $incidents];
        }

        return $grouped;
    }

    /** The count against one kind, zero where nothing of that kind was filed. */
    public function kindCount(TaxonomyKind $kind): int
    {
        return $this->kindCounts[$kind->getCode()] ?? 0;
    }

    /**
     * THE MONTH'S MIX BY KIND, biggest share first — what the donut draws an arc
     * per, and its legend a row per. A kind with nothing filed this month is left
     * out: a zero-length arc is not an arc, and a legend row reading "0 · 0%" is
     * noise. Each share carries its own kind, so the arc and the swatch read the
     * same hues the map paints.
     *
     * @return list<array{kind: TaxonomyKind, count: int}>
     */
    public function kindShares(): array
    {
        $shares = [];
        foreach ($this->kinds as $kind) {
            $count = $this->kindCount($kind);
            if ($count > 0) {
                $shares[] = ['kind' => $kind, 'count' => $count];
            }
        }

        usort($shares, static fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return $shares;
    }

    /** How many of this window's incidents sit at one severity — the bars' heights. */
    public function severityCount(IncidentSeverityEnum $severity): int
    {
        return $this->severityCounts[$severity->value] ?? 0;
    }

    /**
     * The severity levels most serious first — the order the bars read in, so the
     * eye lands on the worst of the month first. The enum orders them least-first,
     * which is right for a chip and wrong for a bar chart.
     *
     * @return list<IncidentSeverityEnum>
     */
    public function severitiesWorstFirst(): array
    {
        return array_reverse(IncidentSeverityEnum::ordered());
    }

    /*
     * ── THE CHARTS, STATED FOR THE ATLAS ──────────────────────────────────
     *
     * A widget partial asks for the chart it draws and writes one line of
     * Twig. The arithmetic is {@see IncidentCharts}'s and the drawing is the
     * atlas's; nothing between them is this module's, which is the whole of
     * why the module ships no chart markup and no chart stylesheet.
     */

    /** Six months of filings — the line the map cannot draw. */
    public function trendChart(): AtlasChart
    {
        $months = array_keys($this->monthlyCounts);
        $span = '';
        if ([] !== $months) {
            $first = new \DateTimeImmutable($months[0].'-01');
            $last = new \DateTimeImmutable($months[\count($months) - 1].'-01');
            $span = ' · '.strtolower($first->format('M')).'–'.strtolower($last->format('M')).' '.$last->format('Y');
        }

        return IncidentCharts::trend($this->monthlyCounts, 'filed per month'.$span);
    }

    /** How serious the window's incidents are, worst level first. */
    public function severityChart(): AtlasChart
    {
        $counts = [];
        foreach ($this->severitiesWorstFirst() as $level) {
            $counts[$level->label()] = $this->severityCount($level);
        }

        return IncidentCharts::severity($counts, $this->window('set at verification'));
    }

    /** How many of the window's incidents REACHED each state, in workflow order. */
    public function funnelChart(): AtlasChart
    {
        $reached = $this->reachedCounts();

        $labelled = [];
        foreach ($this->places() as $place) {
            $labelled[$place->label()] = $reached[$place->value] ?? 0;
        }

        return IncidentCharts::funnel($labelled, \sprintf('this window\u{2019}s %d incidents', $this->filedCount));
    }

    /** Incidents by the zone they fall in; a point in no zone is its own row. */
    public function zoneChart(): AtlasChart
    {
        return IncidentCharts::zones($this->zoneCounts, $this->window());
    }

    /**
     * The window's mix by kind, as parts of a whole. The period is the window
     * the page is reading, named the way every other tab on this surface names
     * it, so the one band's axis says what it is a share OF.
     */
    public function kindShareChart(): AtlasChart
    {
        $shares = [];
        foreach ($this->kindShares() as $share) {
            $shares[] = [
                'label' => $share['kind']->getLabel(),
                'cat' => $share['kind']->catIndex(),
                'count' => $share['count'],
            ];
        }

        $period = null === $this->filter->from ? 'this window' : strtolower($this->filter->from->format('F Y'));

        return IncidentCharts::kindShare($period, $shares, $this->window(\sprintf('%d filed', $this->filedCount)));
    }

    /**
     * WHAT A CHART'S TAB SAYS IT IS ABOUT — the window the page is reading,
     * and whatever else that one chart has to qualify itself with. Said once
     * here, so five tabs cannot describe the same window five ways.
     */
    private function window(string $qualifier = ''): string
    {
        $parts = [];
        if (null !== $this->filter->from) {
            $parts[] = strtolower($this->filter->from->format('F Y'));
        }
        if ('' !== $qualifier) {
            $parts[] = $qualifier;
        }

        return implode(' · ', $parts);
    }
}
