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

/**
 * THE OVERVIEW'S STRIP AND ITS BANNER — every figure the area's own overview
 * reads, added over the areas in scope.
 */
final readonly class IncidentOrgSummary
{
    /**
     * @param int                  $areas           the areas in scope that run the module
     * @param int                  $reporting       of those, the ones that filed this month
     * @param \DateTimeImmutable   $month           the first day of the month the strip counts
     * @param int                  $filed           filed this month, every area added
     * @param int                  $inProgress      in progress now, every area added
     * @param int                  $inProgressAreas the areas holding that work
     * @param array<string, int>   $criticalByArea  area name => open critical incidents, areas with none left out
     * @param list<IncidentOrgRow> $pastTerm        open work past its own sub-category's term, area by area
     */
    public function __construct(
        public int $areas,
        public int $reporting,
        public \DateTimeImmutable $month,
        public int $filed,
        public int $inProgress,
        public int $inProgressAreas,
        public array $criticalByArea,
        public array $pastTerm,
    ) {
    }

    public function criticalOpen(): int
    {
        return array_sum($this->criticalByArea);
    }

    /** The areas the late work is spread across. */
    public function pastTermAreas(): int
    {
        return \count(array_unique(array_map(static fn (IncidentOrgRow $row): string => $row->area->uuid(), $this->pastTerm)));
    }
}
