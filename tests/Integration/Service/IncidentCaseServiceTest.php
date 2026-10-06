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
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentLink;
use Uhifadhi\Incident\Entity\IncidentParty;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Enum\PartyRoleEnum;
use Uhifadhi\Incident\Exception\IncidentCaseException;
use Uhifadhi\Incident\Exception\IncidentTransitionException;
use Uhifadhi\Incident\Service\IncidentCaseService;
use Uhifadhi\Incident\Service\IncidentTransitionService;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * THE CASE FILE'S WRITE SURFACE, asserted against the database rather than
 * against the object graph.
 *
 * The distinction is the whole point of this file. {@see IncidentTransitionService}
 * decides the move and leaves the change in memory — that is what keeps the state
 * machine unit-testable with no database at all — and something has to make the
 * decision durable. Until now that something was the controller, holding an entity
 * manager for one line. It is this service, and these tests never flush: if a write
 * here is not durable on its own, the re-read finds the old value.
 */
final class IncidentCaseServiceTest extends IntegrationTestCase
{
    public function testMovingAnIncidentOnIsDurableWithoutTheCallerFlushing(): void
    {
        $incident = $this->filedIncident();

        $this->cases()->move($incident, IncidentTransitionEnum::Verify, new \DateTimeImmutable('2026-08-20 11:20:00'));

        self::assertSame(IncidentStatusEnum::Verified, $this->reread($incident)->getStatus());
    }

    /** The move leaves an event, and the event is stored with it rather than after it. */
    public function testTheTimelineEventIsStoredWithTheMove(): void
    {
        $incident = $this->filedIncident();
        $before = $incident->getEvents()->count();

        $this->cases()->move($incident, IncidentTransitionEnum::Verify, new \DateTimeImmutable('2026-08-20 11:20:00'));

        self::assertSame($before + 1, $this->reread($incident)->getEvents()->count());
    }

    /**
     * A REFUSAL WRITES NOTHING. The workflow's exception comes through untouched —
     * the endpoint answers 422 with the guard's own sentence — and the row is
     * exactly as it was.
     */
    public function testARefusedMoveLeavesTheRecordAlone(): void
    {
        $incident = $this->filedIncident();

        $this->expectException(IncidentTransitionException::class);

        try {
            $this->cases()->move($incident, IncidentTransitionEnum::Resolve, new \DateTimeImmutable('2026-08-20 11:20:00'));
        } finally {
            self::assertSame(IncidentStatusEnum::Reported, $this->reread($incident)->getStatus());
        }
    }

    /** The clock's own move is durable on the same terms. */
    public function testTheClockCloseIsDurableToo(): void
    {
        $incident = $this->filedIncident();
        $cases = $this->cases();
        $at = new \DateTimeImmutable('2026-08-20 11:20:00');
        foreach ([IncidentTransitionEnum::Verify, IncidentTransitionEnum::Respond, IncidentTransitionEnum::Resolve] as $step) {
            $cases->move($incident, $step, $at = $at->modify('+1 hour'));
        }

        $cases->closeIfDue($incident, $at->modify('+31 days'));

        self::assertSame(IncidentStatusEnum::Closed, $this->reread($incident)->getStatus());
    }

    /** An incident that is not yet due stays where it is, and says so with a null. */
    public function testAnIncidentThatIsNotDueIsNotClosed(): void
    {
        $incident = $this->filedIncident();

        self::assertNull($this->cases()->closeIfDue($incident, new \DateTimeImmutable('2026-08-21 09:00:00')));
        self::assertSame(IncidentStatusEnum::Reported, $this->reread($incident)->getStatus());
    }

    /**
     * A PARTY IS ADDED THROUGH THE SERVICE, and the timeline says so. The design
     * refuses four tables for four roles, so this refuses four methods: the role
     * is an argument.
     */
    public function testAPartyIsAddedWithItsRoleAndItsDescription(): void
    {
        $incident = $this->filedIncident();

        $this->cases()->addParty(
            $incident,
            PartyRoleEnum::Claimant,
            'N. Olesikari',
            'household head · Riverside sub-village',
            at: new \DateTimeImmutable('2026-08-20 06:10:00'),
        );

        $parties = $this->reread($incident)->getParties();
        self::assertCount(1, $parties);
        $party = $parties->first();
        self::assertInstanceOf(IncidentParty::class, $party);
        self::assertSame(PartyRoleEnum::Claimant, $party->getRole());
        self::assertSame('N. Olesikari', $party->getName());
        self::assertSame('household head · Riverside sub-village', $party->getDescribedAs());
    }

    /** An animal is a party too — that is what lets a repeat offender be recognised. */
    public function testAnAnimalIsAParty(): void
    {
        $incident = $this->filedIncident();

        $party = $this->cases()->addParty($incident, PartyRoleEnum::Animal, 'Lion · single adult, unmarked');

        self::assertSame("\u{2014}", $party->initials());
        self::assertCount(1, $this->reread($incident)->getParties());
    }

    /** A party who has an account carries it, so the org chart can find them again. */
    public function testAPartyMayCarryAnAccount(): void
    {
        $incident = $this->filedIncident();
        $ranger = $this->aUser('s.laizer@example.test', 'Salome', 'Laizer');

        $this->cases()->addParty($incident, PartyRoleEnum::Verifier, 'S. Laizer', user: $ranger);

        $party = $this->reread($incident)->getParties()->first();
        self::assertInstanceOf(IncidentParty::class, $party);
        self::assertSame($ranger->getId(), $party->getUser()?->getId());
    }

    /** Adding somebody to a case file is an event on it. */
    public function testAddingAPartyLeavesATimelineEvent(): void
    {
        $incident = $this->filedIncident();
        $before = $incident->getEvents()->count();

        $this->cases()->addParty($incident, PartyRoleEnum::Witness, 'M. Kisioki');

        self::assertSame($before + 1, $this->reread($incident)->getEvents()->count());
    }

    public function testAnIncidentIsAssignedToSomebody(): void
    {
        $incident = $this->filedIncident();
        $responder = $this->aUser('j.mollel@example.test', 'Joseph', 'Mollel');

        $this->cases()->assign($incident, $responder);

        self::assertSame($responder->getId(), $this->reread($incident)->getAssignedTo()?->getId());
    }

    /** Handing a case back to nobody is a real move, and it is recorded like one. */
    public function testAnIncidentIsUnassigned(): void
    {
        $incident = $this->filedIncident();
        $cases = $this->cases();
        $cases->assign($incident, $this->aUser('j.mollel@example.test'));

        $cases->assign($incident, null);

        self::assertNull($this->reread($incident)->getAssignedTo());
    }

    public function testAssigningLeavesATimelineEvent(): void
    {
        $incident = $this->filedIncident();
        $before = $incident->getEvents()->count();

        $this->cases()->assign($incident, $this->aUser('j.mollel@example.test'));

        self::assertSame($before + 1, $this->reread($incident)->getEvents()->count());
    }

    /**
     * THE TIMELINE IS READ BY EVERYBODY WHO READS THE CASE, and a sentence
     * stored on it cannot be masked per reader the way a page asks
     * AddressSight, so a person without a name is never named by their address.
     */
    public function testAssigningSomebodyWithoutANameLeavesNoAddressOnTheTimeline(): void
    {
        $incident = $this->filedIncident();

        $this->cases()->assign($incident, $this->aUser('nameless@example.test', '', ''));

        $bodies = array_map(static fn ($event): string => $event->getBody(), $this->reread($incident)->getEvents()->toArray());
        self::assertNotSame([], $bodies);
        foreach ($bodies as $body) {
            self::assertStringNotContainsString('nameless@example.test', $body);
        }
        self::assertContains('Assigned to a person with no name on their account.', $bodies);
    }

    /** A LINK IS A CLAIM, so it carries who made it and what they claimed. */
    public function testTwoIncidentsAreLinkedWithTheClaimAndItsAuthor(): void
    {
        $area = $this->anAreaWithTaxonomy();
        $incident = $this->anIncident($area);
        $related = $this->anIncident($area, title: 'Fresh lion tracks 400 m from the bomas');
        $laizer = $this->aUser('s.laizer@example.test', 'Salome', 'Laizer');

        $this->cases()->link($incident, $related, 'Same predator, almost certainly.', actor: $laizer, actorName: 'S. Laizer');

        $links = $this->reread($incident)->getLinks();
        self::assertCount(1, $links);
        $link = $links->first();
        self::assertInstanceOf(IncidentLink::class, $link);
        self::assertSame($related->getReference(), $link->getRelated()->getReference());
        self::assertSame('Same predator, almost certainly.', $link->getNote());
        self::assertSame('S. Laizer', $link->getLinkedByName());
    }

    public function testAnIncidentCannotBeLinkedToItself(): void
    {
        $incident = $this->filedIncident();

        $this->expectException(IncidentCaseException::class);

        $this->cases()->link($incident, $incident);
    }

    /** The same claim twice is one claim, and saying so is better than a constraint violation. */
    public function testTheSameLinkIsRefusedRatherThanDuplicated(): void
    {
        $area = $this->anAreaWithTaxonomy();
        $incident = $this->anIncident($area);
        $related = $this->anIncident($area, title: 'Fresh lion tracks 400 m from the bomas');
        $this->cases()->link($incident, $related);

        $this->expectException(IncidentCaseException::class);

        $this->cases()->link($incident, $related);
    }

    /**
     * A LINK ACROSS AREAS IS A LINK NOBODY CAN FOLLOW. Every read path in this
     * module resolves an incident within the area in its URL and answers 404
     * otherwise, so a card offering "Open →" into another area would draw a door
     * onto a wall.
     */
    public function testALinkAcrossAreasIsRefused(): void
    {
        $here = $this->anAreaWithTaxonomy();
        $incident = $this->anIncident($here);
        $elsewhere = $this->anIncident($this->anAreaWithKinds('Tembo Sector'));

        $this->expectException(IncidentCaseException::class);

        $this->cases()->link($incident, $elsewhere);
    }

    private function anAreaWithTaxonomy(string $name = 'Kifaru Sector'): AreaOfInterest
    {
        return $this->anAreaWithKinds($name);
    }

    private function cases(): IncidentCaseService
    {
        /** @var IncidentCaseService $cases */
        $cases = $this->service('incident.case');

        return $cases;
    }

    private function filedIncident(): Incident
    {
        return $this->anIncident($this->anAreaWithKinds('Kifaru Sector'));
    }

    /** The row as the database holds it, with nothing of this request's in the way. */
    private function reread(Incident $incident): Incident
    {
        $reference = $incident->getReference();
        $this->em->clear();

        $found = $this->em->getRepository(Incident::class)->findOneBy(['reference' => $reference]);
        self::assertNotNull($found);

        return $found;
    }
}
