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

/** The organization's taxonomy, the word on screen, and its areas after the bar. */
final readonly class IncidentOrgKinds
{
    /**
     * @param list<IncidentOrgKind>    $kinds every word filed in some area of the scope
     * @param list<IncidentOrgKindRow> $rows  the selected word's areas the bar lets through
     */
    public function __construct(
        public array $kinds,
        public ?IncidentOrgKind $selected,
        public array $rows,
    ) {
    }

    public function retiredCount(): int
    {
        return \count(array_filter($this->kinds, static fn (IncidentOrgKind $kind): bool => $kind->isRetired()));
    }

    public function activeCount(): int
    {
        return \count($this->kinds) - $this->retiredCount();
    }
}
