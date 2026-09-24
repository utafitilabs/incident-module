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

use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Model\IncidentOrgReading;
use Uhifadhi\Incident\Repository\IncidentRepository;

/**
 * BUILDS THIS MODULE'S READING OF A WHOLE ORGANIZATION, once per render.
 *
 * IT IS THE AREA READING ONE SCOPE WIDER AND NOT A SECOND AGGREGATE. Every
 * row comes from {@see IncidentRepository::findOpenByScope()} — the same
 * query the performance section already asks per area — handed the scope's
 * area or nothing at all. A module that grew its own organization-level sum
 * would have two answers to "how many are open" and no way to say which was
 * right.
 *
 * MEMOISED PER (SCOPE, INSTANT), exactly as {@see IncidentOverviewFigures} is
 * and for the same reason: the dashboard asks this twice in one render — once
 * for the figure in the strip, once for the context its cell reads — and two
 * measurements a second apart would let a strip and the card under it print
 * different numbers.
 *
 * `$now` is handed IN rather than read off the clock. The dashboard states one
 * moment for the whole page, so every cell on it is true at the same instant.
 */
final class IncidentOrgFigures
{
    /** @var array<string, IncidentOrgReading> */
    private array $memo = [];

    public function __construct(
        private readonly IncidentRepository $incidents,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    public function forScope(Scope $scope, \DateTimeImmutable $now): IncidentOrgReading
    {
        $key = ($scope->areaUuid ?? 'organization').'@'.$now->format(\DateTimeInterface::ATOM);

        return $this->memo[$key] ??= $this->build($scope, $now);
    }

    private function build(Scope $scope, \DateTimeImmutable $now): IncidentOrgReading
    {
        $day = $now->setTime(0, 0);

        // NEWEST FIRST, because the card is "the latest" and the bound it
        // applies has to cut the oldest rows rather than an arbitrary end of
        // the query's own order. The set is the backlog, which is the number
        // an operations manager could in principle read one morning.
        $open = $this->incidents->findOpenByScope($scope->areaUuid);
        usort($open, static fn (Incident $a, Incident $b) => [$b->getReportedAt(), $b->getReference()]
            <=> [$a->getReportedAt(), $a->getReference()]);

        return new IncidentOrgReading(
            now: $now,
            open: $open,
            total: $this->incidents->countByScope($scope->areaUuid),
            // A DAY'S ROWS, COUNTED IN PHP. The same window the area's own
            // "filed today" card reads, one scope wider; a day is bounded by
            // the question, which is the rule this module already applies to
            // today's filings.
            filedToday: \count($this->incidents->findByScopeBetween($scope->areaUuid, $day, $day->modify('+1 day'))),
            doorUrl: $this->door($open),
            doorLabel: self::doorLabel($open),
        );
    }

    /**
     * WHERE "VIEW ALL" GOES, and it is honest about what this installation
     * actually has.
     *
     * The module's register is an AREA page — `/areas/{uuid}/modules/incidents`
     * — because that is where a register is edited and a case is opened. So a
     * reading whose open work is all in ONE area has one register behind it and
     * the door goes there, which is exactly what the design draws. A reading
     * spanning several areas has no single page, and the door goes to the areas
     * register rather than picking one area's and calling it everybody's.
     *
     * AN ORGANIZATION-LEVEL INCIDENTS PAGE IS RULED AND NOT BUILT. When this
     * module ships one, this is the one method that changes.
     *
     * @param list<Incident> $open
     */
    private function door(array $open): ?string
    {
        $areas = self::areasIn($open);

        try {
            return 1 === \count($areas)
                ? $this->router->generate('incident_dashboard', ['uuid' => $areas[0]])
                : $this->router->generate('area_index');
        } catch (RouteNotFoundException) {
            // An installation that has not mounted the areas register still
            // draws the card; a door to nowhere would take the dashboard down
            // with it.
            return null;
        }
    }

    /** @param list<Incident> $open */
    private static function doorLabel(array $open): string
    {
        return 1 === \count(self::areasIn($open)) ? 'Incidents' : 'Every area';
    }

    /**
     * The areas the open work is spread across, by uuid.
     *
     * @param list<Incident> $open
     *
     * @return list<string>
     */
    private static function areasIn(array $open): array
    {
        $areas = [];
        foreach ($open as $incident) {
            // An area that has not been persisted has no uuid to address, and
            // an incident cannot be filed against one — but the getter is
            // nullable for the instant before the first flush, so the door
            // skips what it could not link to rather than guessing.
            $uuid = $incident->getArea()->getUuidString();
            if (null !== $uuid) {
                $areas[$uuid] = true;
            }
        }

        return array_keys($areas);
    }
}
