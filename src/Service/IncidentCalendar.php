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

namespace Uhifadhi\Incident\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Contracts\Atlas\CalendarDay;
use Uhifadhi\Contracts\Atlas\CalendarMonth;
use Uhifadhi\Contracts\Atlas\CalendarPill;
use Uhifadhi\Contracts\Atlas\PillHue;
use Uhifadhi\Contracts\Atlas\YearMonth;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Model\IncidentFilter;

/**
 * THE WINDOW'S FILINGS, DAY BY DAY, FED TO THE HOUSE CALENDAR.
 *
 * THE ATLAS OWNS THE MONTH AND THIS OWNS WHAT IS IN IT. The grid, the day
 * head, the cell, its fixed height, the day number, the "+N more" and the
 * stepper are the atlas's, drawn the same way on every calendar in the
 * product. This module says only what was filed on which day — which is the
 * whole bargain, and the reason the dashboard ships no month grid of its own.
 *
 * IT WALKS THE ROWS THE SURFACE ALREADY LOADED. The map, the register and
 * every chart on this dashboard read one query, and so does this: a calendar
 * that asked its own would let the month and the register beside it disagree
 * about how many incidents there were — which is the one thing the whole
 * dashboard is built not to do. So nothing here is a repository call.
 *
 * FILLED IS OPEN AND HOLLOW IS FINISHED, the same reading the map plate uses,
 * so a mark means one thing across the product.
 *
 * A HUE IS NOT A CATEGORY HERE, deliberately and not by omission. The design
 * paints each mark with its kind's hue; `Uhifadhi\Contracts\Atlas\PillHue`
 * publishes five ROLES and no category, so a kind cannot be stated through
 * it — and painting "poaching" as `Good` to reach a colour would be this
 * module lying about what a role means. Every mark therefore wears the
 * subject role, and the kind is in the hover instead. The gap is the
 * contract's and is raised there.
 */
final readonly class IncidentCalendar
{
    public function __construct(
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * The month the page is reading, with one mark per incident filed in it.
     *
     * @param list<Incident> $incidents the window's rows, as the register has them
     */
    public function forWindow(IncidentFilter $filter, array $incidents, \DateTimeImmutable $now): CalendarMonth
    {
        $month = YearMonth::of($filter->from ?? $now);

        /** @var array<string, list<Incident>> $byDay */
        $byDay = [];
        foreach ($incidents as $incident) {
            $byDay[$incident->getReportedAt()->format('Y-m-d')][] = $incident;
        }

        // WHERE "+N more" GOES: the register, showing the same window. The day
        // itself is not an address this module has — a filter's finest grain is
        // a month — so the honest link is the list the mark came out of rather
        // than one that would silently show a different set.
        $register = $this->urls->generate('incident_list', ['uuid' => (string) $filter->area->getUuid()] + $filter->toQuery());

        $days = [];
        foreach ($byDay as $localDate => $filings) {
            // THE DAY READS FORWARD. The register is newest-first, which is
            // right for a list somebody scans and wrong inside a square: a
            // cell holding three of eleven should hold the first three of the
            // day, not the last three.
            usort($filings, static fn (Incident $a, Incident $b) => $a->getReportedAt() <=> $b->getReportedAt());

            $pills = [];
            foreach ($filings as $incident) {
                $pills[] = new CalendarPill(
                    label: $incident->getReference(),
                    hue: PillHue::Subject,
                    url: $this->urls->generate(IncidentMapService::CASE_FILE_ROUTE, [
                        'uuid' => (string) $filter->area->getUuid(),
                        'reference' => $incident->getReference(),
                    ]),
                    closed: !$incident->getStatus()->isOpen(),
                    title: \sprintf('%s · %s', $incident->getKind()->getLabel(), $incident->getTitle()),
                );
            }

            $days[$localDate] = new CalendarDay($localDate, $pills, url: $register);
        }

        return new CalendarMonth($month, $days);
    }
}
