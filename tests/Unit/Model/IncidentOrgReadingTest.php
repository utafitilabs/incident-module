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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\TaxonomyKind;
use Uhifadhi\Incident\Entity\TaxonomySubcategory;
use Uhifadhi\Incident\Model\IncidentAge;
use Uhifadhi\Incident\Model\IncidentOrgReading;

/**
 * THE ORGANIZATION FIGURE AND THE CELL UNDER IT ARE ONE READING, and this is
 * where that is proved without a database: the count in the strip, the count
 * in the card's tab and the rows in the card all come off the same set.
 *
 * THE TERM IS THE SUB-CATEGORY'S, NEVER ONE GLOBAL SLA — the case the state
 * column exists to get right, and the easiest one to regress into a single
 * threshold. A nine-day-old claim against a thirty-day term is comfortable; a
 * four-day-old injury against a 72-hour one is over.
 */
#[CoversClass(IncidentOrgReading::class)]
#[CoversClass(IncidentAge::class)]
final class IncidentOrgReadingTest extends TestCase
{
    /** The design's sample instant for the organization dashboard. */
    private const string NOW = '2026-09-19 11:42:00';

    public function testTheFigureAndTheCardCountTheSameOpenWork(): void
    {
        $reading = $this->reading([
            $this->incident('INC-0314', '2026-09-19 08:12:00', 72),
            $this->incident('INC-0302', '2026-09-11 09:00:00', 72),
        ], filedToday: 1);

        self::assertSame(2, $reading->openCount());
        self::assertSame('2 open · 1 past their term', $reading->cardSubline());
        self::assertSame('1 filed today', $reading->figureSubline());
        self::assertSame('1 past their term', $reading->figureAlarm());
    }

    /** A quiet morning raises nothing: an alarm is a broken promise, not a backlog. */
    public function testNothingPastItsTermRaisesNoAlarm(): void
    {
        $reading = $this->reading([$this->incident('INC-0314', '2026-09-19 08:12:00', 72)], filedToday: 1);

        self::assertNull($reading->figureAlarm());
        self::assertSame('1 open', $reading->cardSubline());
    }

    /**
     * ABSENT IS NOT ZERO. An installation that has never filed anything has not
     * measured nought open incidents, and the module publishes no figure at all
     * — which is a different statement from a register with nothing open in it.
     */
    public function testAScopeWithNoRegisterAtAllIsNotAScopeWithNothingOpen(): void
    {
        self::assertFalse($this->reading([], total: 0)->hasRegister());
        self::assertTrue($this->reading([], total: 12)->hasRegister());
        self::assertSame(0, $this->reading([], total: 12)->openCount());
    }

    /**
     * THE CARD IS BOUNDED. However long the backlog is, the cell lists five
     * rows and keeps its height — the rule every dashboard card follows.
     */
    public function testTheCardListsAtMostFiveRowsAndSaysWhenItIsShowingFewer(): void
    {
        $open = [];
        for ($at = 1; $at <= 8; ++$at) {
            $open[] = $this->incident(\sprintf('INC-03%02d', $at), \sprintf('2026-09-%02d 09:00:00', $at), 720);
        }

        $reading = $this->reading($open);

        self::assertCount(IncidentOrgReading::LATEST, $reading->latest());
        self::assertSame(8, $reading->openCount());
        self::assertTrue($reading->isTruncated());
        self::assertFalse($this->reading(\array_slice($open, 0, 3))->isTruncated());
    }

    /** The rows the card lists are the newest of the set it counted, in the order it was given them. */
    public function testTheRowsAreTheNewestOfTheSetTheFigureCounted(): void
    {
        $newest = $this->incident('INC-0314', '2026-09-19 08:12:00', 720);
        $oldest = $this->incident('INC-0291', '2026-08-31 09:00:00', 720);

        self::assertSame([$newest, $oldest], $this->reading([$newest, $oldest])->latest());
    }

    public function testTheStateOfARowIsReadAgainstItsOwnSubCategorysTerm(): void
    {
        $reading = $this->reading([]);

        // Nine days against a thirty-day term: comfortable.
        $claim = $this->incident('INC-0307', '2026-09-10 11:42:00', 720);
        self::assertSame('ok', $reading->termChip($claim));
        self::assertSame('open · in term', $reading->termWords($claim));

        // Four days against a 72-hour one: over, by the same clock.
        $injury = $this->incident('INC-0291', '2026-09-15 11:42:00', 72);
        self::assertSame('fail', $reading->termChip($injury));
        self::assertSame('1 day over term', $reading->termWords($injury));
    }

    /** The last two days of a term read as a warning, in days once there is a whole one. */
    public function testAtermAboutToRunOutWarnsAndCountsDownInDays(): void
    {
        $reading = $this->reading([]);

        $tomorrow = $this->incident('INC-0309', '2026-09-17 11:42:00', 72);
        self::assertSame('warn', $reading->termChip($tomorrow));
        self::assertSame('open · 1 day left', $reading->termWords($tomorrow));

        $soon = $this->incident('INC-0310', '2026-09-18 11:42:00', 72);
        self::assertSame('warn', $reading->termChip($soon));
        self::assertSame('open · 2 days left', $reading->termWords($soon));

        $hours = $this->incident('INC-0311', '2026-09-16 21:42:00', 72);
        self::assertSame('warn', $reading->termChip($hours));
        self::assertSame('open · 10 hours left', $reading->termWords($hours));
    }

    /** @param list<Incident> $open */
    private function reading(array $open, int $total = 47, int $filedToday = 0): IncidentOrgReading
    {
        return new IncidentOrgReading(
            new \DateTimeImmutable(self::NOW),
            $open,
            $total,
            $filedToday,
            '/areas/index',
            'Every area',
        );
    }

    private function incident(string $reference, string $filedAt, int $termHours): Incident
    {
        $area = new AreaOfInterest()->setSource('test fixture');
        $kind = new TaxonomyKind($area, 'conflict', 'Human–wildlife conflict');
        $subcategory = new TaxonomySubcategory($kind, 'livestock-depredation', 'livestock depredation')
            ->setTermHours($termHours);

        return new Incident(
            $area,
            $subcategory,
            $reference,
            'Lion killed four goats at Riverside',
            '{"type":"Point","coordinates":[-29.55,-3.21]}',
            new \DateTimeImmutable($filedAt),
        );
    }
}
