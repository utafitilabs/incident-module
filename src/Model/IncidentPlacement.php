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

use Uhifadhi\Incident\Enum\OutsideBoundaryEnum;

/**
 * WHERE A REPORT'S POINT LIES AGAINST ITS AREA: how far beyond the boundary
 * (nought inside, null where the area has no boundary to measure from),
 * whether that is outside once the area's tolerance is allowed, and what the
 * area's setting does with a point outside.
 */
final readonly class IncidentPlacement
{
    public function __construct(
        public string $areaName,
        public ?float $metres,
        public bool $outside,
        public OutsideBoundaryEnum $rule,
    ) {
    }

    /** How far beyond the boundary, as the banner says it — "330 m", "1.2 km". */
    public function distance(): string
    {
        $metres = (float) $this->metres;
        if ($metres < 1000.0) {
            return \sprintf('%d m', (int) (round($metres / 10.0) * 10));
        }

        return \sprintf('%s km', rtrim(rtrim(number_format($metres / 1000.0, 1, '.', ''), '0'), '.'));
    }

    /** Whether the web form files it only after the person filing chose to keep the point. */
    public function needsConsent(): bool
    {
        return $this->outside && OutsideBoundaryEnum::Warn === $this->rule;
    }

    /** Whether the web form will not file it. */
    public function refused(): bool
    {
        return $this->outside && OutsideBoundaryEnum::Refuse === $this->rule;
    }
}
