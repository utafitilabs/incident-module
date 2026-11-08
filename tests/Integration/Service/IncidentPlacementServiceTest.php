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

namespace Uhifadhi\Incident\Tests\Integration\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingChange;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingsWriter;
use Uhifadhi\Contracts\Settings\SettingDepth;
use Uhifadhi\Incident\Enum\OutsideBoundaryEnum;
use Uhifadhi\Incident\Service\IncidentPlacementService;
use Uhifadhi\Incident\Settings\PlacementSettings;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * WHETHER A POINT IS OUTSIDE ITS AREA, asked of PostGIS against the area's real
 * boundary (-30..-29 E, -3.6..-2.8 N): how far beyond it, and what the area's
 * setting does with it.
 */
final class IncidentPlacementServiceTest extends IntegrationTestCase
{
    public function testAPointInsideTheBoundaryIsInside(): void
    {
        $placement = $this->placements()->judge($this->anArea(), self::point(-29.75, -3.21));

        self::assertFalse($placement->outside);
        self::assertSame(0.0, $placement->metres);
        self::assertNull($placement->zoneName, 'An area with no zones drawn places nothing in one');
    }

    public function testAPointInAZoneNamesIt(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'Riverbend');

        self::assertSame('Riverbend', $this->placements()->judge($area, self::point(-29.75, -3.21))->zoneName);
    }

    public function testAPointBeyondTheBoundaryIsOutsideByItsDistance(): void
    {
        // 0.011° east of the boundary at 3.21° S is about 1.22 km.
        $placement = $this->placements()->judge($this->anArea('Kilimani'), self::point(-28.989, -3.21));

        self::assertTrue($placement->outside);
        self::assertEqualsWithDelta(1220.0, (float) $placement->metres, 15.0);
        self::assertSame('1.2 km', $placement->distance());
        self::assertSame('Kilimani', $placement->areaName);
        self::assertSame(OutsideBoundaryEnum::Warn, $placement->rule, 'Warn is the default');
    }

    public function testAPointWithinTheToleranceCountsAsInside(): void
    {
        // About 330 m beyond, under the default 500 m.
        $placement = $this->placements()->judge($this->anArea(), self::point(-28.997, -3.21));

        self::assertFalse($placement->outside);
        self::assertGreaterThan(300.0, (float) $placement->metres);
    }

    public function testTheAreasOwnSettingsDecide(): void
    {
        $area = $this->anArea();
        $this->set($area, PlacementSettings::INSIDE_WITHIN, 100);
        $this->set($area, PlacementSettings::OUTSIDE_BOUNDARY, OutsideBoundaryEnum::Refuse->value);

        $placement = $this->placements()->judge($area, self::point(-28.997, -3.21));

        self::assertTrue($placement->outside);
        self::assertSame(OutsideBoundaryEnum::Refuse, $placement->rule);
        self::assertSame('330 m', $placement->distance());
    }

    public function testAnAreaWithNoBoundaryHasNoOutside(): void
    {
        $area = new AreaOfInterest();
        $area->setName('Unmapped');
        $area->setSource('test fixture');
        $this->em->persist($area);
        $this->em->flush();

        $placement = $this->placements()->judge($area, self::point(10.0, 10.0));

        self::assertFalse($placement->outside);
        self::assertNull($placement->metres);
    }

    private function set(AreaOfInterest $area, string $key, int|string $value): void
    {
        /** @var SettingsWriter $writer */
        $writer = $this->service('registry.settings.writer');
        $writer->apply([new SettingChange($key, SettingDepth::Area, (string) $area->getUuidString(), $value)], 'test');
    }

    private function placements(): IncidentPlacementService
    {
        /** @var IncidentPlacementService $placements */
        $placements = $this->service('incident.placement');

        return $placements;
    }

    private static function point(float $lng, float $lat): string
    {
        return \sprintf('{"type":"Point","coordinates":[%.6F,%.6F]}', $lng, $lat);
    }
}
