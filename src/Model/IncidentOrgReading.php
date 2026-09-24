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
 * WHAT THIS MODULE SAYS ON THE ORGANIZATION DASHBOARD, computed once.
 *
 * THE SAME READING AS THE AREA'S, ONE SCOPE WIDER, and that is the whole of
 * the difference: the rows come from the one open-work query the module
 * already answers per area ({@see \Uhifadhi\Incident\Repository\IncidentRepository::findOpenByScope()}),
 * asked with no area rather than with one. The organization's answer IS the
 * areas' answers, so there is no second aggregate to disagree with the first.
 *
 * ONE SET, TWO READERS. The figure in the four-to-a-row strip and the cell
 * under it are derived from THIS object and from nothing else — a strip
 * saying seven over a table listing rows from a set of eight is the failure
 * this shape exists to make impossible.
 *
 * BOUNDED, LIKE EVERY DASHBOARD CARD. The card lists {@see LATEST} rows
 * however large the backlog is, and says how many of the whole it is showing;
 * the height of a cell is never a function of the data behind it.
 *
 * ABSENT IS NOT ZERO. An installation where nothing has ever been filed has
 * not measured nought open incidents — there is nothing yet to measure — so
 * {@see hasRegister()} is false and the module publishes no figure at all.
 * An organization WITH a register and a quiet morning publishes "0", because
 * there the module really did look.
 */
final readonly class IncidentOrgReading
{
    /** How many rows the cell lists. The design's five. */
    public const int LATEST = 5;

    /**
     * How close to its own term an open incident has to be before the state
     * column stops reading as comfortable — the design's "1 day left". Two
     * days, which is the last moment a term can still be planned around.
     */
    public const int WARN_WITHIN_HOURS = 48;

    /**
     * @param list<Incident> $open       every incident still somebody's work, anywhere in the scope, newest first
     * @param int            $total      how many the scope has ever held — what tells a quiet morning from no register
     * @param int            $filedToday how many were filed today, anywhere in the scope
     * @param string|null    $doorUrl    where "view all" goes, or null where the scope has no one page
     * @param string         $doorLabel  what that door is called
     */
    public function __construct(
        public \DateTimeImmutable $now,
        public array $open,
        public int $total,
        public int $filedToday,
        public ?string $doorUrl,
        public string $doorLabel,
    ) {
    }

    /** Whether this scope has a register at all. A scope with none says nothing. */
    public function hasRegister(): bool
    {
        return $this->total > 0;
    }

    public function openCount(): int
    {
        return \count($this->open);
    }

    /**
     * OPEN WORK PAST ITS OWN CATEGORY'S TERM — the one incidents figure that is
     * WRONG rather than merely large, and the only one that raises an alarm.
     *
     * @return list<Incident>
     */
    public function pastTerm(): array
    {
        return array_values(array_filter($this->open, fn (Incident $incident) => $incident->isPastTerm($this->now)));
    }

    public function pastTermCount(): int
    {
        return \count($this->pastTerm());
    }

    /**
     * The newest handful of the open work — the card's rows, bounded.
     *
     * @return list<Incident>
     */
    public function latest(): array
    {
        return \array_slice($this->open, 0, self::LATEST);
    }

    /** Whether the card is showing fewer rows than the scope holds open. */
    public function isTruncated(): bool
    {
        return $this->openCount() > self::LATEST;
    }

    /**
     * WHAT THE FIGURE IS MADE OF: today's intake, said the way the design says
     * it. The part that is WRONG is not in here — see {@see figureAlarm()} —
     * because the strip draws an alarm in the failure colour and a subline in
     * the plain one.
     */
    public function figureSubline(): string
    {
        return \sprintf('%d filed today', $this->filedToday);
    }

    /** The part of the subline that is wrong, or nothing when nothing is. */
    public function figureAlarm(): ?string
    {
        $late = $this->pastTermCount();

        return 0 === $late ? null : \sprintf('%d past their term', $late);
    }

    /** What the cell's tab says after its title: the two counts, in that order. */
    public function cardSubline(): string
    {
        $late = $this->pastTermCount();
        $said = \sprintf('%d open', $this->openCount());

        return 0 === $late ? $said : \sprintf('%s · %d past their term', $said, $late);
    }

    /**
     * WHERE ONE ROW STANDS AGAINST ITS OWN CATEGORY'S TERM, as one of the
     * ruled chip states — never a fourth tone of this module's own invention.
     *
     * Three readings and no others: past the term it was filed under, inside
     * the term but within {@see WARN_WITHIN_HOURS} of it, and comfortably
     * inside. A nine-day-old
     * claim against a thirty-day term is not late; a four-day-old injury
     * against a 72-hour one is, which is why the term is the sub-category's
     * and never one global SLA.
     */
    public function termChip(Incident $incident): string
    {
        if ($incident->isPastTerm($this->now)) {
            return 'fail';
        }

        return $this->hoursLeft($incident) <= self::WARN_WITHIN_HOURS ? 'warn' : 'ok';
    }

    /** The same reading in the design's own words. */
    public function termWords(Incident $incident): string
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
