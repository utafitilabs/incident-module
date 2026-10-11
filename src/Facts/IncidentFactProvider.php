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

namespace Uhifadhi\Incident\Facts;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Facts\FactProviderInterface;
use Uhifadhi\Contracts\Facts\FactRequest;
use Uhifadhi\Contracts\Facts\FactSubject;
use Uhifadhi\Contracts\Facts\FactValue;
use Uhifadhi\Contracts\Facts\FigureDefinition;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Model\PerformanceReadings;
use Uhifadhi\Incident\Repository\IncidentRepository;

/**
 * THE INCIDENT FIGURES THE WORKER FILES ON THE FACTS LEDGER, per area and
 * per period, so the performance page reads stored rows instead of loading
 * every incident of every period on every request.
 *
 * | key | subject | what | additive |
 * |---|---|---|---|
 * | `incidents.area_filed` | area | incidents reported in the period | yes |
 * | `incidents.area_resolved` | area | incidents resolved in the period | yes |
 * | `incidents.area_claims_filed` | area | of those reported, the ones a compensation sub-category files as a claim | yes |
 * | `incidents.area_open_past_target` | area | of those reported, the ones still open and past their sub-category's target at the period's end | no |
 * | `incidents.area_median_days_to_close` | area | the middle time from report to resolution of those resolved in the period | no |
 * | `incidents.area_open_aged_7` … `_over_21` | area | every incident still open, by how long it has been open at the period's end: 0–7 days, 8–14, 15–21, over 21 | no |
 * | `incidents.area_filed_resolved` | area | of those reported, the ones resolved since | yes |
 * | `incidents.area_filed_in_term` | area | of those, the ones resolved inside their sub-category's target | yes |
 * | `incidents.area_fines`, `…_fine_cases` | area | the fines payable on those reported, and how many carry one | yes |
 * | `incidents.area_compensation`, `…_compensation_cases` | area | the compensation payable on those reported, and how many carry it | yes |
 * | `incidents.installation_median_days_to_close` | installation | the same middle over every area's finished work | no |
 *
 * EVERY AREA IS FILED, NOUGHTS WHERE NOTHING HAPPENED: an area that filed
 * nothing filed nothing, which is a measurement. A median over no finished
 * work is no figure. The organization's median is the middle of all its
 * finished work, never a middle of the areas' middles; every other figure of
 * the organization is the sum of its areas'.
 *
 * WHAT IS STILL OPEN IS READ AS IT STANDS when the worker runs, and the
 * module asks the core to file a closed month again when one of its
 * incidents changes ({@see \Uhifadhi\Incident\EventListener\IncidentFactsListener}).
 *
 * @see vendor/uhifadhi/uhifadhi/src/Uhifadhi/Contracts/docs/module-development.md — "Facts a module computes on a schedule"
 */
final readonly class IncidentFactProvider implements FactProviderInterface
{
    /** The slug the module files its figures under, and names in a {@see \Uhifadhi\Contracts\Facts\RecomputeFacts}. */
    public const string SLUG = 'incidents';

    public const string AREA_FILED = 'incidents.area_filed';
    public const string AREA_RESOLVED = 'incidents.area_resolved';
    public const string AREA_CLAIMS_FILED = 'incidents.area_claims_filed';
    public const string AREA_OPEN_PAST_TARGET = 'incidents.area_open_past_target';
    public const string AREA_MEDIAN_DAYS_TO_CLOSE = 'incidents.area_median_days_to_close';
    public const string AREA_OPEN_AGED_7 = 'incidents.area_open_aged_7';
    public const string AREA_OPEN_AGED_14 = 'incidents.area_open_aged_14';
    public const string AREA_OPEN_AGED_21 = 'incidents.area_open_aged_21';
    public const string AREA_OPEN_AGED_OVER_21 = 'incidents.area_open_aged_over_21';
    public const string AREA_FILED_RESOLVED = 'incidents.area_filed_resolved';
    public const string AREA_FILED_IN_TERM = 'incidents.area_filed_in_term';
    public const string AREA_FINES = 'incidents.area_fines';
    public const string AREA_FINE_CASES = 'incidents.area_fine_cases';
    public const string AREA_COMPENSATION = 'incidents.area_compensation';
    public const string AREA_COMPENSATION_CASES = 'incidents.area_compensation_cases';
    public const string INSTALLATION_MEDIAN_DAYS_TO_CLOSE = 'incidents.installation_median_days_to_close';

    /** The open work's four buckets, in the order the age chart draws them. */
    public const array AGED = [
        self::AREA_OPEN_AGED_7,
        self::AREA_OPEN_AGED_14,
        self::AREA_OPEN_AGED_21,
        self::AREA_OPEN_AGED_OVER_21,
    ];

    /** Every area figure, for a page that reads an area's whole set in one batch. */
    public const array AREA_FIGURES = [
        self::AREA_FILED,
        self::AREA_RESOLVED,
        self::AREA_CLAIMS_FILED,
        self::AREA_OPEN_PAST_TARGET,
        self::AREA_MEDIAN_DAYS_TO_CLOSE,
        ...self::AGED,
        self::AREA_FILED_RESOLVED,
        self::AREA_FILED_IN_TERM,
        self::AREA_FINES,
        self::AREA_FINE_CASES,
        self::AREA_COMPENSATION,
        self::AREA_COMPENSATION_CASES,
    ];

    public function __construct(
        private IncidentRepository $incidents,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function moduleSlug(): string
    {
        return self::SLUG;
    }

    public function figures(): array
    {
        return [
            new FigureDefinition(self::AREA_FILED, FactSubject::AREA, additive: true),
            new FigureDefinition(self::AREA_RESOLVED, FactSubject::AREA, additive: true),
            new FigureDefinition(self::AREA_CLAIMS_FILED, FactSubject::AREA, additive: true),
            new FigureDefinition(self::AREA_OPEN_PAST_TARGET, FactSubject::AREA, additive: false),
            new FigureDefinition(self::AREA_MEDIAN_DAYS_TO_CLOSE, FactSubject::AREA, additive: false),
            new FigureDefinition(self::AREA_OPEN_AGED_7, FactSubject::AREA, additive: false),
            new FigureDefinition(self::AREA_OPEN_AGED_14, FactSubject::AREA, additive: false),
            new FigureDefinition(self::AREA_OPEN_AGED_21, FactSubject::AREA, additive: false),
            new FigureDefinition(self::AREA_OPEN_AGED_OVER_21, FactSubject::AREA, additive: false),
            new FigureDefinition(self::AREA_FILED_RESOLVED, FactSubject::AREA, additive: true),
            new FigureDefinition(self::AREA_FILED_IN_TERM, FactSubject::AREA, additive: true),
            new FigureDefinition(self::AREA_FINES, FactSubject::AREA, additive: true),
            new FigureDefinition(self::AREA_FINE_CASES, FactSubject::AREA, additive: true),
            new FigureDefinition(self::AREA_COMPENSATION, FactSubject::AREA, additive: true),
            new FigureDefinition(self::AREA_COMPENSATION_CASES, FactSubject::AREA, additive: true),
            new FigureDefinition(self::INSTALLATION_MEDIAN_DAYS_TO_CLOSE, FactSubject::INSTALLATION, additive: false),
        ];
    }

    public function compute(FactRequest $request): iterable
    {
        $from = $request->period->from;
        $until = $request->period->until;

        $filed = self::byArea($this->incidents->findByScopeBetween(null, $from, $until));
        $resolved = $this->incidents->findResolvedByScopeBetween(null, $from, $until);
        $resolvedByArea = self::byArea($resolved);
        $open = self::byArea($this->incidents->findOpenByScope(null));

        foreach ($this->areaUuids() as $area) {
            if (!$request->covers($area)) {
                continue;
            }

            $reported = PerformanceReadings::ofIncidents($filed[$area] ?? []);
            $finished = PerformanceReadings::ofIncidents($resolvedByArea[$area] ?? []);
            $values = [
                self::AREA_FILED => (float) $reported->filed(),
                self::AREA_RESOLVED => (float) $finished->filed(),
                self::AREA_CLAIMS_FILED => (float) $reported->claimsFiled(),
                self::AREA_OPEN_PAST_TARGET => (float) $reported->openPastTarget($until),
                self::AREA_MEDIAN_DAYS_TO_CLOSE => $finished->medianDaysToClose(),
            ];
            foreach (PerformanceReadings::ofIncidents($open[$area] ?? [])->openAgeBuckets($until) as $index => $count) {
                $values[self::AGED[$index]] = (float) $count;
            }
            $values += self::resolutionAndMoney($filed[$area] ?? []);

            foreach ($values as $key => $value) {
                if ($request->asks($key)) {
                    yield new FactValue(FactSubject::AREA, $area, $key, $value);
                }
            }
        }

        if ($request->asks(self::INSTALLATION_MEDIAN_DAYS_TO_CLOSE) && $request->covers(FactSubject::INSTALLATION_UUID)) {
            yield new FactValue(
                FactSubject::INSTALLATION,
                FactSubject::INSTALLATION_UUID,
                self::INSTALLATION_MEDIAN_DAYS_TO_CLOSE,
                PerformanceReadings::ofIncidents($resolved)->medianDaysToClose(),
            );
        }
    }

    /**
     * Of the incidents reported in a period: how many were resolved, how
     * many inside their own target, and the money payable on them in each
     * direction with how many carry it.
     *
     * @param list<Incident> $incidents
     *
     * @return array<string, float>
     */
    private static function resolutionAndMoney(array $incidents): array
    {
        $figures = [
            self::AREA_FILED_RESOLVED => 0.0,
            self::AREA_FILED_IN_TERM => 0.0,
            self::AREA_FINES => 0.0,
            self::AREA_FINE_CASES => 0.0,
            self::AREA_COMPENSATION => 0.0,
            self::AREA_COMPENSATION_CASES => 0.0,
        ];
        foreach ($incidents as $incident) {
            $resolvedAt = $incident->getResolvedAt();
            if (null !== $resolvedAt) {
                ++$figures[self::AREA_FILED_RESOLVED];
                if (!$incident->isPastTerm($resolvedAt)) {
                    ++$figures[self::AREA_FILED_IN_TERM];
                }
            }

            $money = $incident->getMoney();
            if (null === $money) {
                continue;
            }
            [$total, $cases] = MoneyDirectionEnum::Fine === $money->getDirection()
                ? [self::AREA_FINES, self::AREA_FINE_CASES]
                : [self::AREA_COMPENSATION, self::AREA_COMPENSATION_CASES];
            $figures[$total] += $money->payable();
            ++$figures[$cases];
        }

        return $figures;
    }

    /**
     * @param list<Incident> $incidents
     *
     * @return array<string, list<Incident>> area uuid to its incidents
     */
    private static function byArea(array $incidents): array
    {
        $byArea = [];
        foreach ($incidents as $incident) {
            $byArea[(string) $incident->getArea()->getUuidString()][] = $incident;
        }

        return $byArea;
    }

    /** @return list<string> */
    private function areaUuids(): array
    {
        $uuids = [];
        foreach ($this->entityManager->getRepository(AreaOfInterest::class)->findAll() as $area) {
            $uuids[] = (string) $area->getUuidString();
        }

        return $uuids;
    }
}
