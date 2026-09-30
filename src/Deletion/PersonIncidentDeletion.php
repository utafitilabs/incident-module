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

namespace Uhifadhi\Incident\Deletion;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Contracts\Deletion\DeletionContributorInterface;
use Uhifadhi\Contracts\Deletion\DeletionLine;
use Uhifadhi\Contracts\Deletion\DeletionSubject;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Incident\Entity\Incident;

/**
 * WHAT A DELETED PERSON REPORTED GOES WITH THEM (ruled 28 Sep, #48): the
 * incidents they reported, with their evidence. The database would only
 * unlink them; this removes them before the account goes.
 */
final readonly class PersonIncidentDeletion implements DeletionContributorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private IncidentFileRemover $files,
    ) {
    }

    public function supports(object $record): bool
    {
        return $record instanceof UserInterface;
    }

    public function describe(object $record): ?DeletionSubject
    {
        return null;
    }

    public function whatGoes(object $record): array
    {
        \assert($record instanceof UserInterface);
        $n = \count($this->reported($record));

        return $n > 0 ? [new DeletionLine('incidents they reported', $n, singular: 'incident they reported')] : [];
    }

    public function whatStays(object $record): array
    {
        return [];
    }

    public function delete(object $record): void
    {
        \assert($record instanceof UserInterface);
        foreach ($this->reported($record) as $incident) {
            $this->files->remove(IncidentFileRemover::evidenceOf($incident, $this->entityManager));
            $this->entityManager->remove($incident);
        }
        $this->entityManager->flush();
    }

    /** @return list<Incident> */
    private function reported(UserInterface $person): array
    {
        /** @var list<Incident> $incidents */
        $incidents = $this->entityManager->createQuery(\sprintf('SELECT i FROM %s i WHERE i.reportedBy = :person', Incident::class))
            ->setParameter('person', $person)->getResult();

        return $incidents;
    }
}
