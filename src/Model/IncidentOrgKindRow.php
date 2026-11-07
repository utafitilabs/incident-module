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

/** One area's figures for one kind: the area kinds page's own row, named by its area. */
final readonly class IncidentOrgKindRow
{
    public function __construct(
        public IncidentOrgArea $area,
        public IncidentKindFigures $figures,
    ) {
    }
}
