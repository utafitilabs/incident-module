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

namespace Uhifadhi\Incident\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartKind;
use Uhifadhi\Incident\Model\IncidentCharts;

/**
 * WHAT THE DASHBOARD SAYS ITS CHARTS ARE.
 *
 * The module states a kind, an axis and its series; what that LOOKS like —
 * the colours, the grid, the axes, the legend, the height — is the atlas's.
 * So what is worth testing here is the STATEMENT: that a trend is a line and
 * not bars, that a share keeps one band per kind wearing that kind's own
 * category, that a funnel cannot widen, and that a month nobody filed in is
 * empty rather than a row of noughts.
 */
final class IncidentChartsTest extends TestCase
{
    public function testTheTrendIsALineOverTheMonthsInOrder(): void
    {
        $chart = IncidentCharts::trend(['2026-04' => 3, '2026-05' => 0, '2026-06' => 7]);

        self::assertSame(ChartKind::Line, $chart->kind);
        self::assertSame(['apr', 'may', 'jun'], $chart->labels);
        self::assertCount(1, $chart->series);
        self::assertSame([3.0, 0.0, 7.0], $chart->series[0]->points);
    }

    public function testASeverityChartIsOneBarPerLevelInTheOrderItIsGiven(): void
    {
        $chart = IncidentCharts::severity(['critical' => 2, 'high' => 5, 'low' => 0]);

        self::assertSame(ChartKind::Bar, $chart->kind);
        self::assertSame(['critical', 'high', 'low'], $chart->labels);
        self::assertSame([2.0, 5.0, 0.0], $chart->series[0]->points);
    }

    /**
     * ONE BAND PER KIND, AND EACH WEARS ITS OWN CATEGORY — the position the
     * map pin and the register chip read, so an arc, a square and a pin can
     * never drift apart.
     */
    public function testAShareKeepsEachKindsOwnCategory(): void
    {
        $chart = IncidentCharts::kindShare('august 2026', [
            ['label' => 'Human–wildlife conflict', 'cat' => 2, 'count' => 18],
            ['label' => 'Poaching', 'cat' => 1, 'count' => 12],
        ]);

        self::assertSame(ChartKind::Stacked, $chart->kind);
        self::assertSame(['august 2026'], $chart->labels, 'A share is one period, read as parts of a whole.');
        self::assertCount(2, $chart->series);
        self::assertSame('Human–wildlife conflict', $chart->series[0]->label);
        self::assertSame(2, $chart->series[0]->cat);
        self::assertSame([18.0], $chart->series[0]->points);
        self::assertSame(1, $chart->series[1]->cat);
    }

    /** A FUNNEL CANNOT WIDEN: it is stated in the order the workflow runs. */
    public function testAFunnelKeepsTheWorkflowOrder(): void
    {
        $chart = IncidentCharts::funnel(['reported' => 47, 'verified' => 31, 'closed' => 9]);

        self::assertSame(ChartKind::Bar, $chart->kind);
        self::assertSame(['reported', 'verified', 'closed'], $chart->labels);
        self::assertSame([47.0, 31.0, 9.0], $chart->series[0]->points);
    }

    /** A POINT IN NO ZONE IS A ROW OF ITS OWN, never a dropped record. */
    public function testAnUnzonedCountIsNamed(): void
    {
        $chart = IncidentCharts::zones(['Crater floor' => 6, '' => 2]);

        self::assertSame(['Crater floor', 'unzoned'], $chart->labels);
        self::assertSame([6.0, 2.0], $chart->series[0]->points);
    }

    /**
     * NOTHING TO DRAW AND A ROW OF NOUGHTS ARE DIFFERENT ANSWERS, and the
     * chart keeps them different.
     *
     * A window with no zones in it and a month with no kind filed have NO
     * axis — nobody published a point — and the atlas says so in the house's
     * own words. A severity of nought in a month that had incidents is a
     * real reading and is drawn, because the levels are all there whether or
     * not anybody reached them.
     */
    public function testNothingToDrawIsNotTheSameAsARowOfNoughts(): void
    {
        self::assertTrue(IncidentCharts::zones([])->isEmpty());
        self::assertTrue(IncidentCharts::kindShare('august 2026', [])->isEmpty());
        self::assertTrue(IncidentCharts::trend([])->isEmpty());

        self::assertFalse(IncidentCharts::severity(['critical' => 0, 'low' => 0])->isEmpty());
        self::assertFalse(IncidentCharts::trend(['2026-04' => 1])->isEmpty());
    }
}
