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

namespace Uhifadhi\Incident\Enum;

/**
 * WHAT THE WEB FORM DOES WITH A POINT OUTSIDE THE AREA'S BOUNDARY, as an area's
 * setting says. Every one of them files a report from a phone.
 */
enum OutsideBoundaryEnum: string
{
    /** Filed as it is, and marked outside the area. */
    case Accept = 'accept';

    /** Filed once the person filing has been told and chose to keep the point. */
    case Warn = 'warn';

    /** Not filed from the web form until the point is inside. */
    case Refuse = 'refuse';

    public function label(): string
    {
        return match ($this) {
            self::Accept => 'Accept',
            self::Warn => 'Warn',
            self::Refuse => 'Refuse',
        };
    }

    /** What the choice does, as the line under the default's choice says it. */
    public function means(): string
    {
        return match ($this) {
            self::Accept => 'filed as now, marked outside the area',
            self::Warn => 'filed after the person filing is told and may move it',
            self::Refuse => 'the web form will not file it until the point is inside',
        };
    }
}
