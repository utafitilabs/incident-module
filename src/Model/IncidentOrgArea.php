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

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;

/**
 * ONE AREA AS THE ORGANIZATION PAGES NAME IT: the area, and its 1-based place
 * in the declared order. The shell turns the place into a swatch; this module
 * names no hue for an area and reads none.
 */
final readonly class IncidentOrgArea
{
    public function __construct(
        public AreaOfInterest $area,
        public int $position,
    ) {
    }

    public function name(): string
    {
        return (string) $this->area->getName();
    }

    public function uuid(): string
    {
        return (string) $this->area->getUuidString();
    }
}
