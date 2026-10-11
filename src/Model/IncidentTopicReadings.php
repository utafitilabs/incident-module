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

use Uhifadhi\Contracts\Facts\Fact;
use Uhifadhi\Contracts\Facts\FactPeriod;
use Uhifadhi\Contracts\Facts\FactReaderInterface;
use Uhifadhi\Contracts\Facts\FactSubject;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Incident\Facts\IncidentFactProvider;

/**
 * WHAT THE WORKER FILED FOR THE PERFORMANCE PAGE'S GROUND, period by period —
 * read once per period for every area the page holds, and asked again by
 * every figure, row and chart line that needs it.
 *
 * One instance answers one drawing of the topic: it remembers what it read,
 * so a page asks the ledger a fixed number of questions however many
 * incidents were filed.
 *
 * A GROUND OF ONE AREA reads that area's rows; a ground of every area reads
 * the sum of theirs, and the organization's own median, since a middle of
 * middles is not the middle of the work. NULL IS NO FIGURE: a period nobody
 * computed, or an area the worker has not reached yet, is never a nought.
 */
final class IncidentTopicReadings
{
    /** @var array<string, array{areas: array<string, array<string, Fact>>, median: Fact|null}> period key to what was filed */
    private array $filed = [];

    /**
     * @param list<string> $areaUuids every area the page's ground holds
     */
    public function __construct(
        private readonly FactReaderInterface $facts,
        private readonly array $areaUuids,
    ) {
    }

    /** One of the area figures for the ground in one period: the area's, or every area's added up. */
    public function figure(IncidentTopicGround $ground, FigurePeriod $period, string $key): ?float
    {
        $filed = $this->at($period)['areas'];

        $sum = 0.0;
        foreach ($this->areasOf($ground) as $area) {
            $value = ($filed[$area][$key] ?? null)?->value;
            if (null === $value) {
                return null;
            }
            $sum += $value;
        }

        return [] === $this->areasOf($ground) ? null : $sum;
    }

    /** The middle time to close in one period: the area's own, or the organization's. */
    public function median(IncidentTopicGround $ground, FigurePeriod $period): ?float
    {
        return $this->medianFact($ground, $period)?->value;
    }

    /**
     * WHEN THE GROUND'S FIGURES OF ONE PERIOD ARE TRUE AS OF: the oldest of
     * those still open; null when every one is its period's final figure, or
     * none was filed.
     */
    public function asOf(IncidentTopicGround $ground, FigurePeriod $period): ?\DateTimeImmutable
    {
        $facts = [];
        $filed = $this->at($period)['areas'];
        foreach ($this->areasOf($ground) as $area) {
            foreach ($filed[$area] ?? [] as $fact) {
                $facts[] = $fact;
            }
        }
        $median = $this->medianFact($ground, $period);
        if (null !== $median) {
            $facts[] = $median;
        }

        $asOf = null;
        foreach ($facts as $fact) {
            if (!$fact->isFinal()) {
                $asOf = null === $asOf || $fact->asOf < $asOf ? $fact->asOf : $asOf;
            }
        }

        return $asOf;
    }

    private function medianFact(IncidentTopicGround $ground, FigurePeriod $period): ?Fact
    {
        $at = $this->at($period);

        return null === $ground->areaUuid
            ? $at['median']
            : ($at['areas'][$ground->areaUuid][IncidentFactProvider::AREA_MEDIAN_DAYS_TO_CLOSE] ?? null);
    }

    /** @return list<string> */
    private function areasOf(IncidentTopicGround $ground): array
    {
        return null === $ground->areaUuid ? $this->areaUuids : [$ground->areaUuid];
    }

    /** @return array{areas: array<string, array<string, Fact>>, median: Fact|null} */
    private function at(FigurePeriod $period): array
    {
        $key = self::periodOf($period)->key;

        return $this->filed[$key] ??= [
            'areas' => [] === $this->areaUuids
                ? []
                : $this->facts->batch(FactSubject::AREA, $this->areaUuids, IncidentFactProvider::AREA_FIGURES, $key),
            'median' => $this->facts->latest(FactSubject::INSTALLATION, FactSubject::INSTALLATION_UUID, IncidentFactProvider::INSTALLATION_MEDIAN_DAYS_TO_CLOSE, $key),
        ];
    }

    /** The ledger period a page's window is: its month, quarter or year. */
    private static function periodOf(FigurePeriod $period): FactPeriod
    {
        foreach ([FactPeriod::month($period->from), FactPeriod::quarter($period->from), FactPeriod::year($period->from)] as $candidate) {
            if ($candidate->from == $period->from && $candidate->until == $period->until) {
                return $candidate;
            }
        }

        $days = (int) $period->from->diff($period->until)->days;
        $last = $period->until->modify('-1 second');

        return match (true) {
            $days > 200 => FactPeriod::year($last),
            $days > 45 => FactPeriod::quarter($last),
            default => FactPeriod::month($last),
        };
    }
}
