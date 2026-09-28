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

use Uhifadhi\Incident\Entity\Incident;

/**
 * WHAT THE "INCIDENTS I REPORTED" CARD SAYS (ME·12 on a person's own
 * dashboard, #19 option A ruled 28 Sep 2026), computed once: this month's
 * count for the tab, the newest handful for the rows, and the chip on each.
 *
 * THE CHIP SPEAKS THE MODULE'S OWN STATES. Open work is read against its own
 * sub-category's term by {@see IncidentTerm} — the reading the organization
 * dashboard's cell uses — and finished work (resolved, closed) says the place
 * it reached on the quiet chip, because finished work is spent, not late.
 *
 * BOUNDED, LIKE EVERY DASHBOARD CARD: {@see LATEST} rows however long the
 * person's history is.
 */
final readonly class IncidentMyReading
{
    /** How many rows the card lists — the same four the observations card beside it lists. */
    public const int LATEST = 4;

    /**
     * @param list<Incident> $reported  the person's own reports, newest first
     * @param int            $thisMonth how many of them were filed in the calendar month of `$now`
     * @param string|null    $reportUrl where "Report one" leads, or null where the person may file nowhere
     */
    public function __construct(
        public \DateTimeImmutable $now,
        private array $reported,
        public int $thisMonth,
        public ?string $reportUrl,
    ) {
    }

    /** @return list<Incident> */
    public function latest(): array
    {
        return \array_slice($this->reported, 0, self::LATEST);
    }

    public function monthSubline(): string
    {
        return \sprintf('%d this month', $this->thisMonth);
    }

    /** The chip state: the term's reading while the work is open, the quiet chip once it is finished. */
    public function chip(Incident $incident): string
    {
        return $incident->getStatus()->isOpen() ? new IncidentTerm($this->now)->chip($incident) : 'idle';
    }

    /** The chip's words: the term's while open, the workflow's own name for the place once finished. */
    public function words(Incident $incident): string
    {
        return $incident->getStatus()->isOpen() ? new IncidentTerm($this->now)->words($incident) : $incident->getStatus()->label();
    }

    /** Whether it was filed on the calendar day of `$now`, read in `$now`'s zone — the row then says "today". */
    public function isToday(Incident $incident): bool
    {
        return $incident->getReportedAt()->setTimezone($this->now->getTimezone())->format('Y-m-d') === $this->now->format('Y-m-d');
    }
}
