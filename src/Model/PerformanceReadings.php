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
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;

/**
 * THE ARITHMETIC BEHIND THE INCIDENTS TOPIC, over one window's records and
 * nothing else.
 *
 * IT IS PURE, AND THAT IS THE POINT. Every figure the performance page prints
 * — filed, open past target, the median time to close, compensation claims,
 * resolved, and the age distribution behind the second chart — is a reading of
 * a list of {@see IncidentReading}s. Written here rather than inside the topic,
 * each one is provable without a database, and the topic is left doing what a
 * provider is supposed to do: naming figures and handing them over.
 *
 * ONE LIST READ MANY WAYS, never one query per figure. The plates of a period
 * are readings of the same rows, and asking the database once per plate is how
 * two figures on one card come to disagree.
 *
 * THE LIST IS A PIECE OF GROUND'S, NEVER A PERSON'S. What narrows these rows
 * is the scope they were recorded in; nothing here knows or asks who recorded
 * them.
 *
 * NOTHING RECORDED IS NOT NOUGHT. {@see isEmpty()} is what a caller turns into
 * a hole in a six-period history; a median over no finished work is null, not
 * zero; and a count of nothing that happened IS zero, because somebody looked.
 */
final readonly class PerformanceReadings
{
    /** The four buckets the age chart draws, as the upper bound of each in hours. */
    private const array AGE_BUCKET_HOURS = [168, 336, 504];

    /** @param list<IncidentReading> $readings */
    public function __construct(public array $readings)
    {
    }

    /**
     * THE ROWS, REDUCED TO WHAT A FIGURE IS MADE OF. Nobody is on a reading:
     * figures follow scope, not people.
     *
     * @param list<Incident> $incidents
     */
    public static function ofIncidents(array $incidents): self
    {
        $readings = [];
        foreach ($incidents as $incident) {
            $subcategory = $incident->getSubcategory();
            // A CLAIM IS FILED BY THE WORDS, NOT BY THE MONEY CARD. A
            // compensation-bearing sub-category means somebody has asked; the
            // money row is what the assessment writes afterwards, and a claim
            // nobody has costed yet is still a claim.
            $claimed = MoneyDirectionEnum::Compensation === $subcategory->getMoneyDirection();
            $money = $incident->getMoney();

            $readings[] = new IncidentReading(
                reportedAt: $incident->getReportedAt(),
                resolvedAt: $incident->getResolvedAt(),
                termHours: $subcategory->getTermHours(),
                open: $incident->getStatus()->isOpen(),
                claimed: $claimed,
                claimOutstanding: $claimed && (null === $money || (!$money->isSettled() && !$money->isWaived())),
            );
        }

        return new self($readings);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return [] === $this->readings;
    }

    /** How many were filed in the window. */
    public function filed(): int
    {
        return \count($this->readings);
    }

    /**
     * How many are still open AND have already run past what their
     * sub-category promised, as at an instant.
     */
    public function openPastTarget(\DateTimeImmutable $at): int
    {
        $past = 0;
        foreach ($this->readings as $reading) {
            if ($reading->open && $reading->pastTerm($at)) {
                ++$past;
            }
        }

        return $past;
    }

    /**
     * The middle time to close, in days — NULL while nothing has been
     * finished, because a median of no finished work is not nought days.
     */
    public function medianDaysToClose(): ?float
    {
        $days = [];
        foreach ($this->readings as $reading) {
            $closed = $reading->daysToClose();
            if (null !== $closed) {
                $days[] = $closed;
            }
        }

        if ([] === $days) {
            return null;
        }

        sort($days);
        $count = \count($days);
        $middle = intdiv($count, 2);

        return 0 === $count % 2
            ? ($days[$middle - 1] + $days[$middle]) / 2.0
            : $days[$middle];
    }

    /**
     * How many filed a compensation claim at all — the matrix column, which
     * counts the work that arrived rather than the work still outstanding.
     */
    public function claimsFiled(): int
    {
        $claims = 0;
        foreach ($this->readings as $reading) {
            if ($reading->claimed) {
                ++$claims;
            }
        }

        return $claims;
    }

    /**
     * How many of those claims nobody has settled or waived — the headline
     * figure, which is about the queue and not about the intake.
     */
    public function claimsOpen(): int
    {
        $open = 0;
        foreach ($this->readings as $reading) {
            if ($reading->claimOutstanding) {
                ++$open;
            }
        }

        return $open;
    }

    /** How many were finished — of the rows in this window, whatever the window means. */
    public function resolved(): int
    {
        $resolved = 0;
        foreach ($this->readings as $reading) {
            if (null !== $reading->resolvedAt) {
                ++$resolved;
            }
        }

        return $resolved;
    }

    /**
     * THE DISTINCT TERMS THESE RECORDS WERE JUDGED AGAINST, in days, smallest
     * first — what the median figure's caption names, because a median printed
     * without saying what it was promised against is unreadable.
     *
     * @return list<float>
     */
    public function termsInDays(): array
    {
        $terms = [];
        foreach ($this->readings as $reading) {
            $terms[] = $reading->termHours / 24.0;
        }

        $terms = array_values(array_unique($terms));
        sort($terms);

        return $terms;
    }

    /**
     * HOW LONG THE OPEN ONES HAVE BEEN OPEN: four buckets, 0–7 days, 8–14,
     * 15–21 and over three weeks.
     *
     * @return list<int>
     */
    public function openAgeBuckets(\DateTimeImmutable $at): array
    {
        $buckets = [0, 0, 0, 0];
        foreach ($this->readings as $reading) {
            if (!$reading->open) {
                continue;
            }

            $hours = $reading->ageInHours($at);
            $bucket = \count(self::AGE_BUCKET_HOURS);
            foreach (self::AGE_BUCKET_HOURS as $index => $upper) {
                if ($hours < $upper) {
                    $bucket = $index;

                    break;
                }
            }
            ++$buckets[$bucket];
        }

        return $buckets;
    }
}
