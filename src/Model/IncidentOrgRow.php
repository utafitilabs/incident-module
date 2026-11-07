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

/** One incident of the organization register, and the area it was filed in. */
final readonly class IncidentOrgRow
{
    public function __construct(
        public IncidentOrgArea $area,
        public Incident $incident,
    ) {
    }
}
