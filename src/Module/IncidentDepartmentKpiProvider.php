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
use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\DepartmentKpiProviderInterface;
use Uhifadhi\Contracts\Kpi\DepartmentRef;
use Uhifadhi\Incident\Access\IncidentConcerns;
use Uhifadhi\Incident\Access\IncidentDoors;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Repository\IncidentRepository;

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
 */
final class IncidentDepartmentKpiProvider implements DepartmentKpiProviderInterface
{
    /** How many months of history the sparklines carry, including the current one. */
    private const int SPARK_MONTHS = 6;

    public function __construct(
        private readonly IncidentRepository $incidents,
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
        $nextMonth = $monthStart->modify('+1 month');
        $previousStart = $monthStart->modify('-1 month');

        $month = $this->incidents->findByScopeBetween($department->areaUuid, $monthStart, $nextMonth);
        $previous = $this->incidents->findByScopeBetween($department->areaUuid, $previousStart, $monthStart);

        // Nothing recorded in scope in either month: report
        // NOTHING rather than a row of zeros. The host draws a dashed labelled
        // slot, which is the truthful rendering of "we have no reading".
        if ([] === $month && [] === $previous) {
            return [];
        }

        $caption = \sprintf(
            '%s module · %s',
            $this->name,
            null === $department->areaUuid ? 'incidents recorded across the organization' : 'incidents recorded in this area',
        );
        $kpis = [
            new DepartmentKpi(
                'incidents',
                'Incidents recorded',
                $this->slug,
                $this->name,
                (float) \count($month),
                '',
                (float) \count($previous),
                $this->spark($department->areaUuid, $monthStart, static fn (array $rows): float => (float) \count($rows)),
                $caption,
            ),
            new DepartmentKpi(
                'incidents_resolved',
                'Incidents resolved',
                $this->slug,
                $this->name,
                (float) self::resolvedCount($month),
                '',
                (float) self::resolvedCount($previous),
                $this->spark($department->areaUuid, $monthStart, static fn (array $rows): float => (float) self::resolvedCount($rows)),
                $caption,
            ),
            new DepartmentKpi(
                'incidents_in_term',
                'Resolved within term',
                $this->slug,
                $this->name,
                self::withinTermShare($month),
                DepartmentKpi::SHARE,
                self::withinTermShare($previous),
                $this->spark($department->areaUuid, $monthStart, static fn (array $rows): ?float => self::withinTermShare($rows)),
                // Its own provenance line: the term is the CATEGORY's, not a
                // global setting, and a share printed without saying what it was
                // measured against is unreadable.
                $caption.' · against each category’s own term',
            ),
        ];

        // A list of pairs, not a map: an enum case cannot be an array key, and
        // the two directions must stay two plates — the design refuses to add a
        // fine and a claim together anywhere, and a performance page is not an
        // exception.
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

        foreach ([
            [MoneyDirectionEnum::Fine, 'Fines assessed'],
            [MoneyDirectionEnum::Compensation, 'Compensation approved'],
        ] as [$direction, $label]) {
            $value = self::money($month, $direction);
            $was = self::money($previous, $direction);
            // Absent entirely rather than dashed, when nothing in scope carries
            // money of this kind: a plate for a fact that does not apply
            // is clutter, and the host's dashed slot means "we could not measure",
            // not "this is not our work".
            if (null === $value && null === $was) {
                continue;
            }

            $kpis[] = new DepartmentKpi(
                'incidents_'.$direction->value,
                $label,
                $this->slug,
                $this->name,
                null === $value ? null : (float) $value,
                $this->currency,
                null === $was ? null : (float) $was,
                $this->spark($department->areaUuid, $monthStart, static fn (array $rows): ?float => null === ($m = self::money($rows, $direction)) ? null : (float) $m),
                $caption,
            );
        }

        return $kpis;
    }

    /**
     * The last {@see SPARK_MONTHS} months of one figure, oldest first. A month
     * the scope could not be measured in contributes nothing rather than a
     * zero, so a sparkline never dips to the floor because a reading is missing.
     *
     * @param callable(list<Incident>): ?float $reading
     *
     * @return list<float>
     */
    private function spark(?string $areaUuid, \DateTimeImmutable $monthStart, callable $reading): array
    {
        $series = [];
        for ($back = self::SPARK_MONTHS - 1; $back >= 0; --$back) {
            $from = $monthStart->modify(\sprintf('-%d months', $back));
            $value = $reading($this->incidents->findByScopeBetween($areaUuid, $from, $from->modify('+1 month')));
            if (null !== $value) {
                $series[] = $value;
            }
        }

        return $series;
    }

    /** @param list<Incident> $incidents */
    private static function resolvedCount(array $incidents): int
    {
        $resolved = 0;
        foreach ($incidents as $incident) {
            if (null !== $incident->getResolvedAt()) {
                ++$resolved;
            }
        }

        return $resolved;
    }

    /**
     * The share of this month's RESOLVED work that finished inside its own
     * category's term. Null while nothing has been resolved — a month with no
     * finished work has no compliance rate, and printing 0% would read as total
     * failure rather than as no data.
     *
     * @param list<Incident> $incidents
     */
    private static function withinTermShare(array $incidents): ?float
    {
        $resolved = 0;
        $inTerm = 0;
        foreach ($incidents as $incident) {
            $resolvedAt = $incident->getResolvedAt();
            if (null === $resolvedAt) {
                continue;
            }
            ++$resolved;
            if (!$incident->isPastTerm($resolvedAt)) {
                ++$inTerm;
            }
        }

        return 0 === $resolved ? null : $inTerm / $resolved * 100.0;
    }

    /**
     * The money on the record in one direction, or NULL where nothing in scope
     * carries it.
     *
     * @param list<Incident> $incidents
     */
    private static function money(array $incidents, MoneyDirectionEnum $direction): ?int
    {
        $total = null;
        foreach ($incidents as $incident) {
            $money = $incident->getMoney();
            if (null === $money || $money->getDirection() !== $direction) {
                continue;
            }
            $total = ($total ?? 0) + $money->payable();
        }

        return $total;
    }
}
