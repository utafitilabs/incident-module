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
 * THE ORGANIZATION REGISTER: the rows the question lets through, and the bar's
 * options with their counts.
 *
 * THE COUNTS ARE THE COUNTS UNDER THE MONTH AND THE SEARCH, across every area
 * the reader may open, so an option says how many it would show before it is
 * chosen and the area chip lists every area whatever the scope.
 */
final readonly class IncidentOrgRegister
{
    /**
     * @param list<IncidentOrgRow>                                           $rows        newest first
     * @param int                                                            $filed       every row under the month and the search
     * @param list<array{area: IncidentOrgArea, count: int}>                 $areas       every area, in the declared order
     * @param array<string, array{label: string, catIndex: int, count: int}> $categories  wire-code => the word
     * @param array<string, int>                                             $departments department => rows of the kinds it leads
     */
    public function __construct(
        public array $rows,
        public int $filed,
        public array $areas,
        public array $categories,
        public array $departments,
    ) {
    }
}
