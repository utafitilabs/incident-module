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
 * ONE WORD OF THE ORGANIZATION'S TAXONOMY, and who files against it.
 *
 * The word is the wire-code every area shares; its figures are each area's
 * own kinds page, added. A word some area has retired says so: it is retired
 * in that many areas, and still filed in the rest.
 */
final readonly class IncidentOrgKind
{
    /**
     * @param list<IncidentOrgKindRow> $rows every area that has the word, in the declared order
     */
    public function __construct(
        public string $code,
        public string $label,
        public int $catIndex,
        public array $rows,
    ) {
    }

    public function thisMonth(): int
    {
        return array_sum(array_map(static fn (IncidentOrgKindRow $row): int => $row->figures->thisMonth, $this->rows));
    }

    /** The areas where the word is in use. */
    public function inUse(): int
    {
        return \count(array_filter($this->rows, static fn (IncidentOrgKindRow $row): bool => $row->figures->active));
    }

    /** The areas that have retired it. */
    public function retiredIn(): int
    {
        return \count($this->rows) - $this->inUse();
    }

    public function isRetired(): bool
    {
        return $this->retiredIn() > 0;
    }
}
