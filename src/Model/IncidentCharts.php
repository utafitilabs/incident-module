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
use Uhifadhi\Bundle\AtlasBundle\Model\ChartKind;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSeries;

/**
 * THIS SURFACE'S FIGURES, STATED FOR THE ATLAS.
 *
 * THE MODULE SAYS WHAT ITS SERIES IS AND THE ATLAS DRAWS IT. A kind, an
 * axis, the points and — where it carries meaning — the category a band
 * wears; the colours, the grid, the axes, the legend and the height are the
 * atlas's, so a bar here and a bar on any other surface in the product are
 * the same bar. This module drew its own SVG for a while, with the
 * arithmetic — the coordinates, the arc lengths, the bar heights — in a Twig
 * macro file; the ruling that the atlas owns every module visual is what
 * replaced it, and the macros are gone rather than deprecated because
 * nothing outside this module ever imported them.
 *
 * EVERY METHOD IS STATIC AND TAKES PLAIN FIGURES. A chart is a pure reading
 * of numbers the dashboard already computed, so it needs no collaborator and
 * no fixture to test: {@see IncidentDashboard} calls these with what it
 * holds, and a test calls them with three rows.
 *
 * NOTHING TO DRAW AND A ROW OF NOUGHTS ARE DIFFERENT ANSWERS. A chart whose
 * input is empty has no axis at all, and the atlas says so in the house's
 * own words; a nought against a level that exists is a real reading and is
 * drawn, because dropping it would redraw the chart differently on a quiet
 * month and the reader would think the vocabulary had changed.
 */
final readonly class IncidentCharts
{
    /**
     * FILINGS OVER TIME — the one reading on this surface whose window is
     * not the page's month.
     *
     * @param array<string, int> $monthlyCounts 'Y-m' => filings that month, oldest first
     */
    public static function trend(array $monthlyCounts, string $unit = ''): AtlasChart
    {
        $labels = [];
        $points = [];
        foreach ($monthlyCounts as $month => $count) {
            $labels[] = strtolower(new \DateTimeImmutable($month.'-01')->format('M'));
            $points[] = (float) $count;
        }

        return new AtlasChart(
            kind: ChartKind::Line,
            labels: $labels,
            series: [] === $points ? [] : [new ChartSeries('Filed', $points)],
            unit: $unit,
        );
    }

    /**
     * HOW SERIOUS THE WINDOW'S INCIDENTS ARE — one bar per level the model
     * carries, in the order it is handed them (worst first, so the eye lands
     * on the worst of the month).
     *
     * @param array<string, int> $counts level label => count
     */
    public static function severity(array $counts, string $unit = ''): AtlasChart
    {
        return self::oneSeries($counts, 'Incidents', $unit);
    }

    /**
     * HOW MANY REACHED EACH STATE — not how many are sitting in it. Stated in
     * the order the workflow runs, which is the only thing that keeps a
     * funnel from widening.
     *
     * @param array<string, int> $reached state label => how many got at least this far
     */
    public static function funnel(array $reached, string $unit = ''): AtlasChart
    {
        return self::oneSeries($reached, 'Reached', $unit);
    }

    /**
     * INCIDENTS BY THE ZONE THEY FALL IN. Zones are presence-driven and do
     * not overlap, so an incident falls in exactly one and the counts add up
     * to the window. A point in NO zone is a row of its own rather than a
     * dropped record: an organization that has drawn no zones at all is the
     * normal state.
     *
     * @param array<string, int> $counts zone name ('' = unzoned) => count
     */
    public static function zones(array $counts, string $unit = ''): AtlasChart
    {
        $named = [];
        foreach ($counts as $zone => $count) {
            $named['' === $zone ? 'unzoned' : $zone] = $count;
        }

        return self::oneSeries($named, 'Incidents', $unit);
    }

    /**
     * THE WINDOW'S MIX BY KIND, AS PARTS OF A WHOLE — one band per kind,
     * biggest first, over the one period the page is about.
     *
     * A BAND WEARS ITS KIND'S OWN CATEGORY, which is the position the map pin
     * and the register chip read, so a band, a square and a pin can never
     * drift apart. What that position looks like is resolved where the chart
     * is drawn; this module never knows what the second hue is.
     *
     * @param list<array{label: string, cat: int, count: int}> $shares biggest first
     */
    public static function kindShare(string $period, array $shares, string $unit = ''): AtlasChart
    {
        $series = [];
        foreach ($shares as $share) {
            $series[] = new ChartSeries($share['label'], [(float) $share['count']], cat: $share['cat']);
        }

        return new AtlasChart(
            kind: ChartKind::Stacked,
            labels: [] === $series ? [] : [$period],
            series: $series,
            unit: $unit,
        );
    }

    /**
     * One set of bars over a labelled axis — the shape three of the five
     * readings above have, said once.
     *
     * @param array<string, int> $counts
     */
    private static function oneSeries(array $counts, string $label, string $unit = ''): AtlasChart
    {
        $points = [];
        foreach ($counts as $count) {
            $points[] = (float) $count;
        }

        return new AtlasChart(
            kind: ChartKind::Bar,
            labels: array_keys($counts),
            series: [] === $points ? [] : [new ChartSeries($label, $points)],
            unit: $unit,
        );
    }
}
