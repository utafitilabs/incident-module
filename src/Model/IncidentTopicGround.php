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
 * THE GROUND A SLICE OF THE PERFORMANCE PAGE IS MEASURED OVER, AND SINCE WHEN
 * — the two facts every figure on the page needs and neither of which is a
 * record of this module's.
 *
 * WHY "SINCE WHEN" TRAVELS WITH THE GROUND. Every period before this module
 * was switched on where the reader can see it is a period nobody was
 * recording, and a nought drawn there is a collapse the organization never
 * had. The distinction has to be made wherever a run of periods is built, so
 * the fact that decides it rides along rather than being fetched again at each
 * call site.
 *
 * BOTH FACTS COME FROM THE HOST'S DIRECTORY, never from a read of somebody
 * else's tables: {@see \Uhifadhi\Contracts\Performance\DepartmentEntry}
 * carries the area a row reads and its `runningSince` for this module's slug.
 */
final readonly class IncidentTopicGround
{
    public function __construct(
        /** The one area this reads, or NULL to roll every area up. */
        public ?string $areaUuid,
        /**
         * When this module started running where this ground's reader can see
         * it. Null dates no holes — the ledger simply does not say, which is
         * not a reason to invent a gap.
         */
        public ?\DateTimeImmutable $runningSince = null,
    ) {
    }

    /** Whether this module was recording over the ground at any instant of a period. */
    public function measured(FigurePeriod $period): bool
    {
        return null === $this->runningSince || $this->runningSince < $period->until;
    }
}
