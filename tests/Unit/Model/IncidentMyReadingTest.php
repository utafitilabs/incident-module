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
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Model\IncidentMyReading;
use Uhifadhi\Incident\Model\IncidentTerm;

/**
 * THE "INCIDENTS I REPORTED" CARD, read without a database: the count in its
 * tab, the rows under it and the chip on each row.
 *
 * THE CHIP SPEAKS THE MODULE'S OWN WORDS. Open work is read against its own
 * sub-category's term exactly as the organization dashboard's cell reads it,
 * and finished work says the place it reached — so one incident never reads
 * two ways on two dashboards.
 */
#[CoversClass(IncidentMyReading::class)]
#[CoversClass(IncidentTerm::class)]
final class IncidentMyReadingTest extends TestCase
{
    /** Monday 28 September 2026, mid-morning — the day the design was ruled. */
    private const string NOW = '2026-09-28 11:42:00';

    public function testTheTabSaysHowManyWereReportedThisMonth(): void
    {
        self::assertSame('2 this month', $this->reading([], thisMonth: 2)->monthSubline());
        self::assertSame('0 this month', $this->reading([], thisMonth: 0)->monthSubline());
    }

    /** Bounded, like every dashboard card: the newest handful, in the order given. */
    public function testTheCardListsTheNewestHandfulAndNoMore(): void
    {
        $reported = [];
        for ($day = 20; $day >= 14; --$day) {
            $reported[] = $this->incident(\sprintf('INC-00%02d', $day), \sprintf('2026-09-%02d 09:00:00', $day), 720);
        }

        $latest = $this->reading($reported)->latest();

        self::assertCount(IncidentMyReading::LATEST, $latest);
        self::assertSame(\array_slice($reported, 0, IncidentMyReading::LATEST), $latest);
    }

    public function testOpenWorkIsReadAgainstItsOwnSubCategorysTerm(): void
    {
        $reading = $this->reading([]);

        $claim = $this->incident('INC-0021', '2026-09-28 08:15:00', 720);
        self::assertSame('ok', $reading->chip($claim));
        self::assertSame('open · in term', $reading->words($claim));

        $injury = $this->incident('INC-0019', '2026-09-24 11:42:00', 72);
        self::assertSame('fail', $reading->chip($injury));
        self::assertSame('1 day over term', $reading->words($injury));

        $soon = $this->incident('INC-0020', '2026-09-26 11:42:00', 72);
        self::assertSame('warn', $reading->chip($soon));
        self::assertSame('open · 1 day left', $reading->words($soon));
    }

    /** Finished work is spent, not late: it says the place it reached, quietly. */
    public function testFinishedWorkSaysThePlaceItReachedOnTheQuietChip(): void
    {
        $reading = $this->reading([]);

        $resolved = $this->incident('INC-0018', '2026-09-01 09:00:00', 72)->setStatus(IncidentStatusEnum::Resolved);
        self::assertSame('idle', $reading->chip($resolved));
        self::assertSame('resolved', $reading->words($resolved));

        $closed = $this->incident('INC-0017', '2026-08-01 09:00:00', 72)->setStatus(IncidentStatusEnum::Closed);
        self::assertSame('idle', $reading->chip($closed));
        self::assertSame('closed', $reading->words($closed));
    }

    /** "today 08:15" for this morning's, a date for anything older. */
    public function testTodaysReportsAreSaidToBeToday(): void
    {
        $reading = $this->reading([]);

        self::assertTrue($reading->isToday($this->incident('INC-0021', '2026-09-28 00:05:00', 720)));
        self::assertFalse($reading->isToday($this->incident('INC-0019', '2026-09-27 23:55:00', 720)));
    }

    /** The door is drawn only where there is somewhere to report. */
    public function testTheDoorIsOnlyWhereThereIsSomewhereToReport(): void
    {
        self::assertNull($this->reading([])->reportUrl);
        self::assertSame('/report', $this->reading([], reportUrl: '/report')->reportUrl);
    }

    /** THE SAME TERM READING THE ORGANIZATION'S CELL USES, not a second copy of it. */
    public function testTheTermReadingIsTheOneTheOrganizationCellUses(): void
    {
        $term = new IncidentTerm(new \DateTimeImmutable(self::NOW));
        $incident = $this->incident('INC-0020', '2026-09-26 11:42:00', 72);

        self::assertSame($term->chip($incident), $this->reading([])->chip($incident));
        self::assertSame($term->words($incident), $this->reading([])->words($incident));
    }

    /** @param list<Incident> $reported */
    private function reading(array $reported, int $thisMonth = 0, ?string $reportUrl = null): IncidentMyReading
    {
        return new IncidentMyReading(new \DateTimeImmutable(self::NOW), $reported, $thisMonth, $reportUrl);
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
