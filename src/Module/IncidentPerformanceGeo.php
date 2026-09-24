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

namespace Uhifadhi\Incident\Module;

use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\ZoneRepository;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Performance\ColumnPolarity;
use Uhifadhi\Contracts\Performance\GeoFigure;
use Uhifadhi\Contracts\Performance\GeoSeries;
use Uhifadhi\Contracts\Performance\PerformanceGeoProviderInterface;
use Uhifadhi\Contracts\Performance\PerformanceScope;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Repository\IncidentRepository;

/**
 * WHAT THE INCIDENTS TOPIC HAS TO SAY ABOUT THE GROUND — filings per area, and
 * filings per zone of one area.
 *
 * BESIDE THE TOPIC, NOT INSIDE IT. Most topics have nothing to say about
 * where: staffing does not, goals do not, and a `geo()` on the topic contract
 * would make every module answer a question it has no answer to. This module
 * does have an answer — an incident is a point on the ground before it is
 * anything else — so it implements the optional seam and the page draws the
 * figures on the atlas plate, with the same chrome and the same legend as
 * every other map in the product.
 *
 * ── THE SAME READING THE TOPIC'S "FILED" CARD MAKES ──────────────────────────
 * The area plate counts exactly the rows {@see IncidentPerformanceTopic}'s
 * Filed figure counts — {@see IncidentRepository::findByScopeBetween()}, the
 * page's scope, the page's period — grouped by the area each was filed in. A
 * plate that asked a question of its own could disagree with the card above
 * it, and two numbers about one month is the one thing a director cannot
 * resolve from the page.
 *
 * The zone plate is the one figure that cannot be read off those rows: THE
 * POINT DECIDES, NOT THE STAMP, exactly as
 * {@see IncidentZoneFigureProvider} states, so it is answered by the same
 * point-in-ring count the zone surfaces read
 * ({@see IncidentRepository::countFiledByZoneBetween()}). An area that redraws
 * its zones therefore reads its history through the new geography at once, and
 * an incident whose point lies in no zone counts for no zone while still
 * counting for its area.
 *
 * ── GROUND BY IDENTIFIER ONLY ────────────────────────────────────────────────
 * A figure names its ground by uuid and by the name the page prints, never by
 * a polygon. The area bundle owns the shapes and draws them; this module
 * carries no geometry across the seam and has no opinion about how the plate
 * is hued beyond stating which way is good.
 *
 * ── FIGURES FOLLOW SCOPE ─────────────────────────────────────────────────────
 * The organization's page reads every area and is handed no zone plate — zones
 * belong to one area, and a plate of every zone in the organization is two
 * geographies in one legend. An area's page reads that area on the area plate
 * AND its zones underneath, which is what the overview's "Incidents by zone"
 * card draws.
 *
 * ── THE THREE ABSENCES, KEPT APART ───────────────────────────────────────────
 *  - GROUND THIS MODULE SAYS NOTHING ABOUT is absent from the series, and a
 *    series with no figures is not published at all. The page then says, in
 *    the house's own words, that nobody publishes ground figures here — which
 *    is not the same page as an empty map.
 *  - A NULL VALUE is ground this module knows and could not measure: every
 *    zone of an area this module has never recorded on. It cannot tell "the
 *    module is switched on and the month was quiet" from "the module was never
 *    switched on here" without reading the registry's area x module ledger,
 *    which lives in a package it does not depend on and which the topic
 *    already refuses to read.
 *  - A NOUGHT IS A READING. A zone of an area this module demonstrably records
 *    on, with nothing filed on its ground in the period, is nought: somebody
 *    was looking.
 *
 * ── POLARITY ─────────────────────────────────────────────────────────────────
 * FILING IS NEITHER GOOD NOR BAD ({@see ColumnPolarity::None}), for the reason
 * the topic's Filed column states: an area with more incidents filed may
 * simply be an area where people report, which is the behaviour this module
 * exists to encourage. A plate hues a placing, and hue without polarity is a
 * plate claiming that more is better.
 */
final readonly class IncidentPerformanceGeo implements PerformanceGeoProviderInterface
{
    /** Filings per area — the organization's plate, and one area's own. */
    public const string BY_AREA = 'incidents.filed_by_area';

    /** Filings per zone of one area — the overview's "Incidents by zone" card. */
    public const string BY_ZONE = 'incidents.filed_by_zone';

    /**
     * WHAT THE LEGEND PRINTS BESIDE THE NUMBER. A matrix column has a header to
     * carry its word and leaves this empty; a plate has a ramp and a hover, and
     * a bare figure on one reads as a measurement of nothing in particular.
     */
    private const string UNIT = 'incidents';

    public function __construct(
        private IncidentRepository $incidents,
        private AreaOfInterestRepository $areas,
        private ZoneRepository $zones,
        /** The slug this module is registered under in the registry's catalogue. */
        private string $slug,
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    /**
     * ONE PLATE ON THE ORGANIZATION'S PAGE, TWO ON AN AREA'S — and neither
     * where this module has nothing to say about the ground.
     *
     * @return list<GeoSeries>
     */
    public function geo(PerformanceScope $scope, FigurePeriod $period): array
    {
        $series = [];

        $byArea = self::areaSeries($this->filedByArea($scope, $period));
        if (!$byArea->isEmpty()) {
            $series[] = $byArea;
        }

        $areaUuid = $scope->areaUuid;
        if (null !== $areaUuid) {
            $byZone = self::zoneSeries($areaUuid, $this->filedByZone($areaUuid, $period));
            if (!$byZone->isEmpty()) {
                $series[] = $byZone;
            }
        }

        return $series;
    }

    /**
     * THE AREA PLATE'S SHAPE, stated where a test can read it without a
     * database: what it is over, which way is good, and what its legend says.
     *
     * @param list<GeoFigure> $figures
     */
    public static function areaSeries(array $figures): GeoSeries
    {
        return new GeoSeries(
            key: self::BY_AREA,
            title: 'Incidents filed, by area',
            figures: $figures,
            over: GeoSeries::OVER_AREAS,
            unit: self::UNIT,
            polarity: ColumnPolarity::None,
            caption: 'Filed in the period, counted where each was recorded. Filing is neither an achievement nor a failure, so this plate is never tinted.',
        );
    }

    /**
     * THE ZONE PLATE'S SHAPE, and it names its area: areas and the zones of one
     * area are two different plates, and a series that did not say would leave
     * the page matching uuids against two tables to find out which it was
     * handed.
     *
     * @param list<GeoFigure> $figures
     */
    public static function zoneSeries(string $areaUuid, array $figures): GeoSeries
    {
        return new GeoSeries(
            key: self::BY_ZONE,
            title: 'Incidents filed, by zone',
            figures: $figures,
            over: GeoSeries::OVER_ZONES,
            areaUuid: $areaUuid,
            unit: self::UNIT,
            polarity: ColumnPolarity::None,
            caption: 'Filed in the period, counted by where the point fell. An incident inside no zone is still the area’s.',
        );
    }

    /**
     * ONE FIGURE PER AREA THAT HELD A FILING IN THE PERIOD, oldest reading
     * first — which is to say in the order the areas are named, so the plate's
     * key reads the same way twice.
     *
     * An area absent here is an area this series says nothing about for this
     * period, which is deliberately weaker than "nothing happened there": see
     * the second absence in the class docblock.
     *
     * @return list<GeoFigure>
     */
    private function filedByArea(PerformanceScope $scope, FigurePeriod $period): array
    {
        $tally = [];
        foreach ($this->incidents->findByScopeBetween($scope->areaUuid, $period->from, $period->until) as $incident) {
            $uuid = $incident->getArea()->getUuidString();
            if (null === $uuid) {
                continue;
            }

            $tally[$uuid] ??= ['label' => self::areaName($incident), 'filed' => 0];
            ++$tally[$uuid]['filed'];
        }

        uasort($tally, static fn (array $one, array $other): int => $one['label'] <=> $other['label']);

        $figures = [];
        foreach ($tally as $uuid => $area) {
            $figures[] = new GeoFigure($uuid, $area['label'], (float) $area['filed']);
        }

        return $figures;
    }

    /**
     * ONE FIGURE PER ZONE OF THE AREA — every zone, drawn or not filed on,
     * because the ring is on the plate either way.
     *
     * THE VALUE IS NULL WHERE THE GROUND WAS NEVER WATCHED. An area this module
     * has no record of at all cannot be told apart from one where it was never
     * switched on, so every zone of it is unknown and the whole plate drops
     * itself. Where the module demonstrably records on the area's ground, a
     * zone with nothing filed in the period is nought.
     *
     * @return list<GeoFigure>
     */
    private function filedByZone(string $areaUuid, FigurePeriod $period): array
    {
        $area = $this->areas->findOneByUuid($areaUuid);
        if (null === $area) {
            return [];
        }

        $zones = $this->zones->zonesFor($area);
        if ([] === $zones) {
            return [];
        }

        $watched = $this->incidents->countFor($area) > 0;
        $uuids = [];
        foreach ($zones as $zone) {
            $uuid = $zone->getUuidString();
            if (null !== $uuid) {
                $uuids[] = $uuid;
            }
        }

        $filed = $this->incidents->countFiledByZoneBetween($uuids, $period->from, $period->until);

        $figures = [];
        foreach ($zones as $zone) {
            $uuid = $zone->getUuidString();
            if (null === $uuid) {
                continue;
            }

            $figures[] = new GeoFigure(
                $uuid,
                self::zoneName($zone),
                $watched ? (float) ($filed[$uuid] ?? 0) : null,
            );
        }

        return $figures;
    }

    /**
     * WHAT THE PLATE CALLS THIS AREA. The name is nullable in the area bundle
     * and the uuid is not, so a nameless area is still a piece of ground with a
     * figure on it rather than a row the plate drops.
     */
    private static function areaName(Incident $incident): string
    {
        return $incident->getArea()->getName() ?? 'Unnamed area';
    }

    /** The same promise for a zone. */
    private static function zoneName(Zone $zone): string
    {
        return $zone->getName() ?? 'Unnamed zone';
    }
}
