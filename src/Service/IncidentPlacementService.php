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

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Settings\SettingsReaderInterface;
use Uhifadhi\Incident\Enum\OutsideBoundaryEnum;
use Uhifadhi\Incident\Model\IncidentPlacement;
use Uhifadhi\Incident\Repository\AreaBoundaryDistance;
use Uhifadhi\Incident\Repository\IncidentZoneLocator;
use Uhifadhi\Incident\Settings\PlacementSettings;

/**
 * WHETHER A REPORT'S POINT IS OUTSIDE ITS AREA, by the area's own Placement
 * settings: beyond the boundary by more than "Inside if within" is outside,
 * and "A report placed outside the area's boundary" says what the web form
 * does with it.
 */
final readonly class IncidentPlacementService
{
    public function __construct(
        private AreaBoundaryDistance $distances,
        private SettingsReaderInterface $settings,
        private IncidentZoneLocator $zones,
    ) {
    }

    public function judge(AreaOfInterest $area, string $position): IncidentPlacement
    {
        $uuid = $area->getUuidString();
        $metres = $this->distances->metresBeyond($area, $position);
        $within = $this->settings->value(PlacementSettings::INSIDE_WITHIN, $uuid);
        $rule = OutsideBoundaryEnum::tryFrom((string) $this->settings->value(PlacementSettings::OUTSIDE_BOUNDARY, $uuid))
            ?? OutsideBoundaryEnum::Warn;

        return new IncidentPlacement(
            (string) $area->getName(),
            $metres,
            null !== $metres && $metres > (is_numeric($within) ? (float) $within : (float) PlacementSettings::DEFAULT_INSIDE_WITHIN_METRES),
            $rule,
            $this->zones->locate($area, $position)?->getName(),
        );
    }
}
