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

namespace Uhifadhi\Incident\Tests\Integration\Module;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Performance\GeoFigure;
use Uhifadhi\Contracts\Performance\GeoSeries;
use Uhifadhi\Contracts\Performance\PerformanceScope;
use Uhifadhi\Incident\Module\IncidentPerformanceGeo;
use Uhifadhi\Incident\Tests\Integration\Fixtures\CollectedGeoProviders;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * THE GROUND FIGURES THIS MODULE PUBLISHES, against real rows in a real
 * PostGIS database — and, for the zones, against real rings, because a zone
 * figure is decided by where the point fell and by nothing else.
 *
 * Every assertion here is about the four things the contract holds a geo
 * provider to: the ground named by identifier and never by geometry, the
 * series saying what it is over, figures following the scope they were asked
 * for, and the absences kept apart — ground this module says nothing about,
 * ground it knows and could not measure, and a nought that is a reading.
 */
final class IncidentPerformanceGeoTest extends IntegrationTestCase
{
    private const string AUGUST = '2026-08-19 09:00:00';

    /** The wired provider, so the tag and the arguments are under test too. */
    private function geo(): IncidentPerformanceGeo
    {
        /** @var IncidentPerformanceGeo $provider */
        $provider = $this->service('incident.performance_geo');

        return $provider;
    }

    private static function period(string $at = self::AUGUST): FigurePeriod
    {
        return FigurePeriod::month(new \DateTimeImmutable($at));
    }

    /** @return array<string, GeoSeries> */
    private function series(PerformanceScope $scope, ?FigurePeriod $period = null): array
    {
        $byKey = [];
        foreach ($this->geo()->geo($scope, $period ?? self::period()) as $series) {
            $byKey[$series->key] = $series;
        }

        return $byKey;
    }

    /** @return array<string, float|null> label => value */
    private static function values(GeoSeries $series): array
    {
        $byLabel = [];
        foreach ($series->figures as $figure) {
            $byLabel[$figure->label] = $figure->value;
        }

        return $byLabel;
    }

    /**
     * TWO AREAS WITH RECORDS IN AUGUST — two filings in the north, one in the
     * south. Who filed them changes nothing: figures follow scope.
     *
     * @return array{north: AreaOfInterest, south: AreaOfInterest}
     */
    private function world(): array
    {
        $north = $this->anAreaWithKinds('North Sector');
        $south = $this->anAreaWithKinds('South Sector');

        $this->anIncident($north, at: new \DateTimeImmutable('2026-08-04 09:00:00'));
        $this->anIncident($north, 'snaring', 'Snare line lifted', new \DateTimeImmutable('2026-08-06 09:00:00'));
        $this->anIncident($south, 'roadkill', 'Zebra roadkill', new \DateTimeImmutable('2026-08-07 09:00:00'));
        $this->em->flush();

        return ['north' => $north, 'south' => $south];
    }

    public function testItPublishesTheIncidentsGroundFigures(): void
    {
        self::assertSame('incidents', $this->geo()->moduleSlug());
    }

    /** The tag is applied by hand; this is what proves it stuck. */
    public function testTheProviderReachesThePlateThroughItsTag(): void
    {
        /** @var CollectedGeoProviders $collected */
        $collected = static::getContainer()->get(CollectedGeoProviders::class);

        self::assertArrayHasKey('incidents', $collected->bySlug());
        self::assertInstanceOf(IncidentPerformanceGeo::class, $collected->bySlug()['incidents']);
    }

    /**
     * THE ORGANIZATION'S PLATE IS ONE FIGURE PER AREA, and the ground is named
     * by its uuid — the area bundle owns the shapes and draws them.
     */
    public function testTheOrganizationReadsOneFigurePerArea(): void
    {
        $world = $this->world();

        $series = $this->series(PerformanceScope::organization());
        self::assertArrayHasKey(IncidentPerformanceGeo::BY_AREA, $series);

        $areas = $series[IncidentPerformanceGeo::BY_AREA];
        self::assertSame(GeoSeries::OVER_AREAS, $areas->over);
        self::assertSame(['North Sector' => 2.0, 'South Sector' => 1.0], self::values($areas));
        self::assertSame(
            [$world['north']->getUuidString(), $world['south']->getUuidString()],
            array_map(static fn (GeoFigure $figure): string => $figure->uuid, $areas->figures),
        );
    }

    /** No zones plate on the organization's page: zones belong to one area. */
    public function testTheOrganizationIsHandedNoZonePlate(): void
    {
        $this->world();

        self::assertArrayNotHasKey(IncidentPerformanceGeo::BY_ZONE, $this->series(PerformanceScope::organization()));
    }

    /**
     * SCOPE IS OBEYED, NOT ASSUMED. An area's page reads that area's ground and
     * no other's — on the area plate as much as on the zone plate.
     */
    public function testAnAreaScopeNarrowsTheAreaPlateToItsOwnGround(): void
    {
        $world = $this->world();

        $scope = PerformanceScope::area((string) $world['north']->getUuidString(), 'North Sector');
        $areas = $this->series($scope)[IncidentPerformanceGeo::BY_AREA];

        self::assertSame(['North Sector' => 2.0], self::values($areas));
    }

    /**
     * THE ZONE PLATE THE OVERVIEW'S "INCIDENTS BY ZONE" CARD DRAWS — every
     * zone of the area, counted by where the point fell.
     */
    public function testAnAreaScopeAlsoReadsItsZones(): void
    {
        $world = $this->world();
        $west = $this->aZone($world['north'], 'West Ridge', -30.0, -29.7);
        $east = $this->aZone($world['north'], 'East Plain', -29.7, -29.0);

        $scope = PerformanceScope::area((string) $world['north']->getUuidString(), 'North Sector');
        $zones = $this->series($scope)[IncidentPerformanceGeo::BY_ZONE];

        self::assertSame(GeoSeries::OVER_ZONES, $zones->over);
        self::assertSame($world['north']->getUuidString(), $zones->areaUuid);
        // BY IDENTIFIER, NEVER BY GEOMETRY — and in the area module's own
        // order, which is by name, so the plate's key reads the same way twice.
        self::assertSame(
            [(string) $east->getUuidString(), (string) $west->getUuidString()],
            array_map(static fn (GeoFigure $figure): string => $figure->uuid, $zones->figures),
        );

        // Both fixtures sit at -29.75, which is west ground. The east plain was
        // watched and held nothing, which is a nought and not a hole.
        self::assertSame(['East Plain' => 0.0, 'West Ridge' => 2.0], self::values($zones));
    }

    /** An area with no zones drawn has no zone plate — the normal state. */
    public function testAnAreaWithNoZonesPublishesNoZonePlate(): void
    {
        $world = $this->world();

        $scope = PerformanceScope::area((string) $world['north']->getUuidString(), 'North Sector');

        self::assertArrayNotHasKey(IncidentPerformanceGeo::BY_ZONE, $this->series($scope));
    }

    /**
     * THE FIRST ABSENCE: ground this module has never recorded on is ground it
     * says nothing about. An empty series is not a plate of noughts, and the
     * page says so in the house's own words rather than drawing an empty map.
     */
    public function testAScopeThisModuleHasNeverRecordedOnPublishesNothing(): void
    {
        $unserved = $this->anAreaWithKinds('Unserved Reserve');
        $this->aZone($unserved, 'Far Corner');

        $scope = PerformanceScope::area((string) $unserved->getUuidString(), 'Unserved Reserve');

        self::assertSame([], $this->geo()->geo($scope, self::period()));
        self::assertSame([], $this->geo()->geo(PerformanceScope::organization(), self::period()));
    }

    /**
     * THE SECOND AND THIRD ABSENCES, TOLD APART. A quiet month over ground this
     * module demonstrably records on is a nought on every zone — somebody was
     * looking — while the area plate, which only knows the period it was
     * asked for, publishes no figure for an area that filed nothing in it.
     */
    public function testAQuietMonthOverWatchedGroundIsNoughtsAndNotAnEmptyPlate(): void
    {
        $world = $this->world();
        $this->aZone($world['north'], 'West Ridge');

        $scope = PerformanceScope::area((string) $world['north']->getUuidString(), 'North Sector');
        $quiet = self::period('2026-09-19 09:00:00');

        $series = $this->series($scope, $quiet);

        self::assertArrayNotHasKey(IncidentPerformanceGeo::BY_AREA, $series);
        self::assertSame(['West Ridge' => 0.0], self::values($series[IncidentPerformanceGeo::BY_ZONE]));
    }

    /** The period is answered, not the module's whole register. */
    public function testOnlyThePeriodAskedForIsCounted(): void
    {
        $world = $this->world();
        $this->anIncident($world['north'], 'snaring', 'July snare', new \DateTimeImmutable('2026-07-04 09:00:00'));
        $this->em->flush();

        $july = self::values($this->series(PerformanceScope::organization(), self::period('2026-07-19 09:00:00'))[IncidentPerformanceGeo::BY_AREA]);

        self::assertSame(['North Sector' => 1.0], $july);
    }
}
