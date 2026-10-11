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

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Contracts\Facts\FactReaderInterface;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\DepartmentKpiProviderInterface;
use Uhifadhi\Contracts\Kpi\DepartmentRef;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Incident\Access\IncidentConcerns;
use Uhifadhi\Incident\Access\IncidentDoors;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Facts\IncidentFactProvider;
use Uhifadhi\Incident\Model\IncidentPlates;
use Uhifadhi\Incident\Model\IncidentTopicGround;
use Uhifadhi\Incident\Model\IncidentTopicReadings;

/**
 * THE INCIDENTS FIGURES A DEPARTMENT'S PERFORMANCE PAGE PRINTS, this month.
 *
 * THE SCOPE, NOT THE RECORDER'S SEAT. The host asks this provider only for
 * departments that attach Incidents, so nothing here filters by department: a
 * ref naming an area reads every incident recorded in that area, and a ref
 * naming none reads every incident across all areas. Who recorded an incident,
 * and whether they hold a position anywhere, plays no part — two departments
 * scoped to the same area read the same figures.
 *
 * ── WHICH FIGURES, AND WHY THESE ─────────────────────────────────────────────
 * The contract states a rule that decides this list: EVERY KPI IT CARRIES IS
 * BETTER WHEN LARGER, and a module with a less-is-better figure must invert it
 * before handing it over. "Open incidents" and "breaches" are both less-is-better
 * and neither inverts honestly — an area with more open incidents may simply be
 * an area where people are reporting, which is the behaviour this module exists
 * to encourage. Punishing a performance page for it would teach exactly the
 * wrong lesson.
 *
 * So the plates are the work, not the backlog:
 *
 *  - **Incidents recorded** — filings. More reporting is better reporting.
 *  - **Incidents resolved** — work finished.
 *  - **Resolved within term** — a SHARE, and the honest reading of "breaches":
 *    the same fact, pointing the right way, and null while nothing has been
 *    resolved rather than 0%.
 *  - **Fines assessed** and **Compensation approved** — money, in TWO plates,
 *    because the design refuses to add the two directions together anywhere and a
 *    performance page is not an exception.
 *
 * NULL IS UNKNOWN AND UNKNOWN IS NOT ZERO. A scope with no incident carrying
 * money gets a dashed slot for the money plates, not a zero: "they
 * assessed nothing" and "nothing they touched could carry a fine" are different
 * facts, and only one of them is about performance.
 *
 * EVERY FIGURE IS WHAT THE WORKER FILED on the facts ledger, per area and per
 * month ({@see IncidentFactProvider}), so the dashboard and a department's
 * record ask a fixed number of questions however many incidents were filed. A
 * month the worker has not filed yet is no figure.
 */
final class IncidentDepartmentKpiProvider implements DepartmentKpiProviderInterface
{
    /** How many months of history the sparklines carry, including the current one. */
    private const int SPARK_MONTHS = 6;

    public function __construct(
        private readonly FactReaderInterface $facts,
        private readonly EntityManagerInterface $entityManager,
        /**
         * WHETHER THE TWO MONEY PLATES MAY BE DRAWN FOR THIS READER, over the
         * scope the ref names — one area, or every area of an
         * organization-wide ref. A plate that totalled an area the reader is
         * refused would smuggle it into a figure.
         */
        private readonly IncidentDoors $doors,
        /** The slug this module is registered under in the host's catalogue. */
        private readonly string $slug,
        private readonly string $name = 'Incidents',
        private readonly string $currency = 'TZS',
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    /**
     * ONE SET OF FIGURES PER CALL, at the ref's scope. A ref carrying an area
     * reads that area's incidents and no other's; a ref carrying none is
     * organization-wide and every area's incidents roll up into the same plates.
     * The department's id and name play no part in the figures.
     *
     * @return list<DepartmentKpi>
     */
    public function kpisFor(DepartmentRef $department, \DateTimeImmutable $now): array
    {
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);
        $thisMonth = FigurePeriod::month($monthStart);
        $lastMonth = FigurePeriod::month($monthStart->modify('-1 month'));

        $ground = new IncidentTopicGround($department->areaUuid);
        $read = $this->readings($department->areaUuid);
        $months = [];
        for ($back = self::SPARK_MONTHS - 1; $back >= 0; --$back) {
            $months[] = FigurePeriod::month($monthStart->modify(\sprintf('-%d months', $back)));
        }

        $filed = $read->figure($ground, $thisMonth, IncidentFactProvider::AREA_FILED);
        $wasFiled = $read->figure($ground, $lastMonth, IncidentFactProvider::AREA_FILED);

        // Nothing recorded in scope in either month: report
        // NOTHING rather than a row of zeros. The host draws a dashed labelled
        // slot, which is the truthful rendering of "we have no reading".
        if (!($filed > 0.0) && !($wasFiled > 0.0)) {
            return [];
        }

        $asOf = $read->asOf($ground, $thisMonth);
        $plates = new IncidentPlates($read, $ground, $thisMonth, $lastMonth, $months, $asOf);

        $caption = \sprintf(
            '%s module · %s',
            $this->name,
            null === $department->areaUuid ? 'incidents recorded across the organization' : 'incidents recorded in this area',
        );
        $kpis = [
            $this->plate($plates, 'incidents', 'Incidents recorded', '', $caption, self::figure(IncidentFactProvider::AREA_FILED)),
            $this->plate($plates, 'incidents_resolved', 'Incidents resolved', '', $caption, self::figure(IncidentFactProvider::AREA_FILED_RESOLVED)),
            // Its own provenance line: the term is the CATEGORY's, not a
            // global setting, and a share printed without saying what it was
            // measured against is unreadable.
            $this->plate($plates, 'incidents_in_term', 'Resolved within term', DepartmentKpi::SHARE, $caption.' · against each category’s own term', self::withinTermShare(...)),
        ];

        /*
         * THE MONEY IS A CONCERN OF ITS OWN, so somebody who reads this
         * department's work and not its money gets the three plates above and
         * NOT these two. Absent, never a nought: a nought is a measurement,
         * and this is the absence of permission to take one. The strip's own
         * dashed slot is not used either — that means "we could not measure",
         * which would be a lie about a figure that was measured and withheld.
         */
        if (!$this->doors->opensAcross(IncidentConcerns::CASE_MONEY, Verb::Read, $department->areaUuid)) {
            return $kpis;
        }

        // The two directions stay two plates — the design refuses to add a
        // fine and a claim together anywhere, and a performance page is not
        // an exception.
        foreach ([
            [MoneyDirectionEnum::Fine, 'Fines assessed', IncidentFactProvider::AREA_FINES, IncidentFactProvider::AREA_FINE_CASES],
            [MoneyDirectionEnum::Compensation, 'Compensation approved', IncidentFactProvider::AREA_COMPENSATION, IncidentFactProvider::AREA_COMPENSATION_CASES],
        ] as [$direction, $label, $total, $cases]) {
            $money = self::money($total, $cases);
            // Absent entirely rather than dashed, when nothing in scope carries
            // money of this kind: a plate for a fact that does not apply
            // is clutter, and the host's dashed slot means "we could not measure",
            // not "this is not our work".
            if (null === $money($read, $ground, $thisMonth) && null === $money($read, $ground, $lastMonth)) {
                continue;
            }

            $kpis[] = $this->plate($plates, 'incidents_'.$direction->value, $label, $this->currency, $caption, $money);
        }

        return $kpis;
    }

    /**
     * One plate: the month's figure, last month's beside it, and the run of
     * months for its sparkline.
     *
     * @param \Closure(IncidentTopicReadings, IncidentTopicGround, FigurePeriod): ?float $reading
     */
    private function plate(IncidentPlates $plates, string $key, string $label, string $unit, string $caption, \Closure $reading): DepartmentKpi
    {
        $values = [];
        foreach ($plates->months as $month) {
            // A month the scope could not be measured in contributes nothing
            // rather than a zero, so a sparkline never dips to the floor
            // because a reading is missing.
            $value = $reading($plates->read, $plates->ground, $month);
            if (null !== $value) {
                $values[] = $value;
            }
        }

        return new DepartmentKpi(
            $key,
            $label,
            $this->slug,
            $this->name,
            $reading($plates->read, $plates->ground, $plates->thisMonth),
            $unit,
            $reading($plates->read, $plates->ground, $plates->lastMonth),
            $values,
            $caption,
            asOf: $plates->asOf,
        );
    }

    /** @return \Closure(IncidentTopicReadings, IncidentTopicGround, FigurePeriod): ?float */
    private static function figure(string $key): \Closure
    {
        return static fn (IncidentTopicReadings $read, IncidentTopicGround $ground, FigurePeriod $month): ?float => $read->figure($ground, $month, $key);
    }

    /**
     * The share of the month's RESOLVED work that finished inside its own
     * category's term. Null while nothing has been resolved — a month with no
     * finished work has no compliance rate, and printing 0% would read as total
     * failure rather than as no data.
     */
    private static function withinTermShare(IncidentTopicReadings $read, IncidentTopicGround $ground, FigurePeriod $month): ?float
    {
        $resolved = $read->figure($ground, $month, IncidentFactProvider::AREA_FILED_RESOLVED);
        $inTerm = $read->figure($ground, $month, IncidentFactProvider::AREA_FILED_IN_TERM);

        return null === $resolved || null === $inTerm || 0.0 === $resolved ? null : $inTerm / $resolved * 100.0;
    }

    /**
     * The money on the record in one direction, or NULL where nothing in scope
     * carries it.
     *
     * @return \Closure(IncidentTopicReadings, IncidentTopicGround, FigurePeriod): ?float
     */
    private static function money(string $total, string $cases): \Closure
    {
        return static function (IncidentTopicReadings $read, IncidentTopicGround $ground, FigurePeriod $month) use ($total, $cases): ?float {
            $carrying = $read->figure($ground, $month, $cases);

            return null === $carrying || 0.0 === $carrying ? null : $read->figure($ground, $month, $total);
        };
    }

    /** What the worker filed for the ref's area, or for every area. */
    private function readings(?string $areaUuid): IncidentTopicReadings
    {
        if (null !== $areaUuid) {
            return new IncidentTopicReadings($this->facts, [$areaUuid]);
        }

        $areas = [];
        foreach ($this->entityManager->getRepository(AreaOfInterest::class)->findAll() as $area) {
            $areas[] = (string) $area->getUuidString();
        }

        return new IncidentTopicReadings($this->facts, $areas);
    }
}
