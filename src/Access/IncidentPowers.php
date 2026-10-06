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

namespace Uhifadhi\Incident\Access;

use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Access\Power;
use Uhifadhi\Contracts\Access\PowerGroup;
use Uhifadhi\Contracts\Access\PowerSourceInterface;
use Uhifadhi\Contracts\Access\PowerTarget;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Contracts\Access\WholeOrganization;
use Uhifadhi\Incident\Repository\IncidentRepository;

/**
 * WHAT THIS MODULE LETS ONE PERSON DO TO ANOTHER PERSON'S INCIDENT, as Team ›
 * Permissions asks it. An incident is filed by one person and worked by
 * others: whoever holds the pair at the area moves it on, settles its money,
 * attaches evidence to it and takes evidence off it, whoever filed it. Each is
 * a change to somebody else's record, so each is declared here.
 *
 * Each question is the pair the route or the upload target asks, of the area
 * the incident lies in — the subject the core's grant voter reads.
 *
 * @see \Uhifadhi\Incident\Controller\IncidentDetailController::transition()
 * @see \Uhifadhi\Incident\Controller\IncidentMoneyController
 * @see \Uhifadhi\Incident\Upload\IncidentEvidenceTarget
 */
final readonly class IncidentPowers implements PowerSourceInterface
{
    private const string MANAGE = IncidentConcerns::INCIDENTS.'.'.Verb::Manage->value;
    private const string MONEY = IncidentConcerns::CASE_MONEY.'.'.Verb::Manage->value;
    private const string ATTACH = IncidentConcerns::CASE_FILES.'.'.Verb::Manage->value;
    private const string REMOVE = IncidentConcerns::CASE_FILES.'.'.Verb::Delete->value;

    public function __construct(
        private IncidentRepository $incidents,
    ) {
    }

    public function powers(): iterable
    {
        $whose = [PowerTarget::AColleague, PowerTarget::SomebodyBeyondTheirArea];
        $group = PowerGroup::OthersRecords;

        yield new Power('move-somebody-elses-incident', $group, 'verify, respond to, resolve or close somebody else’s incident', [self::MANAGE], $whose);
        yield new Power('settle-the-money-on-somebody-elses-incident', $group, 'record or waive the money on somebody else’s incident', [self::MONEY], $whose);
        yield new Power('attach-evidence-to-somebody-elses-incident', $group, 'attach evidence to somebody else’s incident', [self::ATTACH], $whose);
        yield new Power('take-evidence-off-somebody-elses-incident', $group, 'take evidence off somebody else’s incident', [self::REMOVE], $whose);
    }

    /**
     * THE AREA THEIR LATEST INCIDENT LIES IN. Somebody who has filed nothing
     * yet is asked about where their next one would be: the one area they are
     * placed at, or, placed across several, across the organization or
     * nowhere, anywhere at all — which only a reach over the whole
     * organization covers.
     */
    public function subject(Power $power, string $question, PowerTarget $kind, ?object $target): mixed
    {
        if (!$target instanceof User) {
            return null;
        }

        $latest = $this->incidents->findReportedByPerson((string) $target->getUuidString(), 1)[0] ?? null;
        if (null !== $latest) {
            return $latest->getArea();
        }

        $placement = $target->getPlacement();
        $areas = null === $placement || $placement->isWholeOrganization() ? [] : ($placement->getAreas() ?? []);

        return 1 === \count($areas) ? $areas[0] : new WholeOrganization();
    }
}
