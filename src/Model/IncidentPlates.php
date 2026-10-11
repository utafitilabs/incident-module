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

use Uhifadhi\Contracts\Kpi\FigurePeriod;

/**
 * WHAT ONE SET OF DEPARTMENT PLATES IS READ FROM: the stored figures, the
 * ground they are about, the month and the one before it, the months of the
 * sparkline, and when the month's figures were true.
 */
final readonly class IncidentPlates
{
    /**
     * @param list<FigurePeriod> $months the sparkline's, oldest first
     */
    public function __construct(
        public IncidentTopicReadings $read,
        public IncidentTopicGround $ground,
        public FigurePeriod $thisMonth,
        public FigurePeriod $lastMonth,
        public array $months,
        public ?\DateTimeImmutable $asOf,
    ) {
    }
}
