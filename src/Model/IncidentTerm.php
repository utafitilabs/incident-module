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
 * WHERE ONE OPEN INCIDENT STANDS AGAINST ITS OWN CATEGORY'S TERM, at one
 * instant — as one of the ruled chip states and in the design's own words.
 *
 * ONE READING FOR EVERY CARD THIS MODULE HANDS A HOST. The organization
 * dashboard's cell ({@see IncidentOrgReading}) and a person's own "Incidents
 * I reported" card ({@see IncidentMyReading}) both ask this, so one incident
 * never reads "in term" on one dashboard and "late" on the other.
 *
 * Three readings and no others: past the term it was filed under, inside the
 * term but within {@see WARN_WITHIN_HOURS} of it, and comfortably inside. A
 * nine-day-old claim against a thirty-day term is not late; a four-day-old
 * injury against a 72-hour one is, which is why the term is the
 * sub-category's and never one global SLA.
 */
final readonly class IncidentTerm
{
    /**
     * How close to its own term an open incident has to be before the state
     * stops reading as comfortable — the design's "1 day left". Two days,
     * which is the last moment a term can still be planned around.
     */
    public const int WARN_WITHIN_HOURS = 48;

    public function __construct(
        private \DateTimeImmutable $now,
    ) {
    }

    /** The chip state: `fail`, `warn` or `ok` — never a fourth tone of this module's own. */
    public function chip(Incident $incident): string
    {
        if ($incident->isPastTerm($this->now)) {
            return 'fail';
        }

        return $this->hoursLeft($incident) <= self::WARN_WITHIN_HOURS ? 'warn' : 'ok';
    }

    /** The same reading in the design's own words. */
    public function words(Incident $incident): string
    {
        $left = $this->hoursLeft($incident);
        if ($left < 0) {
            return \sprintf('%s over term', IncidentAge::remainingWords(-$left));
        }

        return $left <= self::WARN_WITHIN_HOURS
            ? \sprintf('open · %s left', IncidentAge::remainingWords($left))
            : 'open · in term';
    }

    /** How long this incident has left against its own sub-category's term. Negative once it is over. */
    private function hoursLeft(Incident $incident): int
    {
        return $incident->getSubcategory()->getTermHours() - $incident->ageInHours($this->now);
    }
}
