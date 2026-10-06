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

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvent;
use Uhifadhi\Incident\Entity\IncidentLink;
use Uhifadhi\Incident\Entity\IncidentParty;
use Uhifadhi\Incident\Enum\IncidentEventKindEnum;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Enum\PartyRoleEnum;
use Uhifadhi\Incident\Exception\IncidentCaseException;
use Uhifadhi\Incident\Exception\IncidentTransitionException;
use Uhifadhi\Incident\Repository\IncidentLinkRepository;

/**
 * THE CASE FILE'S WRITE SURFACE — everything that happens to one incident after
 * it is filed, and the place the change becomes durable.
 *
 * WHY THIS SITS BETWEEN A CONTROLLER AND {@see IncidentTransitionService}. The
 * transition service is the state machine and takes no entity manager on purpose:
 * that is what lets every rule the workflow has be asserted in a plain unit test
 * with no database. Somebody still has to write the decision down, and until this
 * class existed that somebody was the controller, holding an entity manager for a
 * single `flush()`. A screen deciding when a domain change is persisted is a
 * screen with an opinion about the domain.
 *
 * SO THE DIVISION IS: the transition service decides and stamps, this service
 * persists, and the controller authorizes and responds. A refusal never reaches
 * the flush — {@see IncidentTransitionException} is thrown before anything is
 * written, and the endpoint answers 422 with the guard's own sentence.
 *
 * ONE SERVICE FOR PARTIES, THE ASSIGNEE AND LINKS, not three. They are writes on
 * one aggregate: each is `new X($incident, …)` self-registering on the incident,
 * a timeline event, and the same flush, and none of them needs a collaborator the
 * others do not. Three one-method services would carry three copies of the event
 * helper and three answers to "when is this durable". Evidence is the deliberate
 * exception and lives in {@see IncidentEvidenceService}, because it crosses into
 * stored bytes and has a failure vocabulary of its own.
 *
 * EVERY WRITE LEAVES A TIMELINE EVENT, for the reason the timeline exists: a
 * column that changed with no trace proves nothing in a hearing. Who to print it
 * under is passed in beside the account, so the record still names them if the
 * account later goes.
 */
final readonly class IncidentCaseService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private IncidentTransitionService $transitions,
        private IncidentLinkRepository $links,
    ) {
    }

    /**
     * MOVE THE INCIDENT ON, durably.
     *
     * @param string|null $actorName who to print the event under — kept beside the
     *                               account so the timeline still names them if the account later goes
     *
     * @throws IncidentTransitionException with the guard's own sentence as its message
     */
    public function move(
        Incident $incident,
        IncidentTransitionEnum $transition,
        \DateTimeImmutable $at,
        ?UserInterface $actor = null,
        ?string $actorName = null,
        ?string $note = null,
    ): IncidentEvent {
        $event = $this->transitions->apply($incident, $transition, $at, $actor, $actorName, $note);
        $this->entityManager->flush();

        return $event;
    }

    /**
     * THE CLOCK'S MOVE, durably — and nothing at all for an incident that is not
     * due, so a sweep can offer every resolved incident and let the workflow
     * decide.
     */
    public function closeIfDue(Incident $incident, \DateTimeImmutable $now): ?IncidentEvent
    {
        $event = $this->transitions->closeIfDue($incident, $now);
        if (null === $event) {
            return null;
        }

        $this->entityManager->flush();

        return $event;
    }

    /**
     * SOMEBODY — OR SOMETHING — INVOLVED, wearing a role. One method and not four:
     * a claimant, a witness, a suspect and the ranger who filed it are the same
     * shape of record, which is why the entity is one table.
     */
    public function addParty(
        Incident $incident,
        PartyRoleEnum $role,
        string $name,
        ?string $describedAs = null,
        ?UserInterface $user = null,
        ?\DateTimeImmutable $at = null,
        ?UserInterface $actor = null,
        ?string $actorName = null,
    ): IncidentParty {
        $party = new IncidentParty($incident, $role, $name)
            ->setDescribedAs($describedAs)
            ->setUser($user);

        $this->entityManager->persist($party);
        $this->note(
            $incident,
            $at ?? new \DateTimeImmutable(),
            $actor,
            $actorName,
            \sprintf('%s added as %s.', $name, $role->label()),
        );

        $this->entityManager->flush();

        return $party;
    }

    /**
     * WHO IS CARRYING IT. Null is a real answer and a real move — handing a case
     * back to nobody is a fact the timeline is entitled to, not the absence of one.
     */
    public function assign(
        Incident $incident,
        ?UserInterface $to,
        ?\DateTimeImmutable $at = null,
        ?UserInterface $actor = null,
        ?string $actorName = null,
    ): void {
        $incident->setAssignedTo($to);

        $this->note(
            $incident,
            $at ?? new \DateTimeImmutable(),
            $actor,
            $actorName,
            null === $to
                ? 'Unassigned.'
                : \sprintf('Assigned to %s.', self::nameOf($to)),
        );

        $this->entityManager->flush();
    }

    /**
     * "THESE TWO ARE RELATED" — and a link is a CLAIM, so it carries who made it
     * and what they claimed.
     *
     * Three refusals, each because the alternative is a link nobody can use: an
     * incident related to itself says nothing; the same claim twice is one claim,
     * and the unique constraint would otherwise answer it as a database error
     * rather than a sentence; and a link across areas would draw an "Open →" onto
     * a page that answers 404, because every read path here resolves an incident
     * within the area in its URL.
     *
     * @throws IncidentCaseException with the sentence the panel prints
     */
    public function link(
        Incident $incident,
        Incident $related,
        ?string $note = null,
        ?\DateTimeImmutable $at = null,
        ?UserInterface $actor = null,
        ?string $actorName = null,
    ): IncidentLink {
        if ($incident === $related) {
            throw new IncidentCaseException('An incident cannot be related to itself.');
        }

        if ($incident->getArea() !== $related->getArea()) {
            throw new IncidentCaseException(\sprintf('%s is in another area, and a link has to be one somebody can follow.', $related->getReference()));
        }

        if (null !== $this->links->findOneBy(['incident' => $incident, 'related' => $related])) {
            throw new IncidentCaseException(\sprintf('%s is already linked to %s.', $incident->getReference(), $related->getReference()));
        }

        $link = new IncidentLink($incident, $related)
            ->setNote($note)
            ->setLinkedBy($actor, $actorName);

        $this->entityManager->persist($link);
        $this->note(
            $incident,
            $at ?? new \DateTimeImmutable(),
            $actor,
            $actorName,
            \sprintf('Linked to %s.%s', $related->getReference(), null === $note ? '' : ' '.$note),
        );

        $this->entityManager->flush();

        return $link;
    }

    private function note(
        Incident $incident,
        \DateTimeImmutable $at,
        ?UserInterface $actor,
        ?string $actorName,
        string $body,
    ): void {
        new IncidentEvent($incident, IncidentEventKindEnum::Note, $at, $body)
            ->withActor($actor, $actorName);
    }

    /**
     * How a person is named in a sentence the timeline prints. Never by their
     * address: the sentence is stored once and read by everybody who reads the
     * case, so it cannot be masked for a reader who may not see addresses.
     */
    private static function nameOf(UserInterface $user): string
    {
        $name = trim((string) $user->getFullName());

        return '' !== $name ? $name : 'a person with no name on their account';
    }
}
