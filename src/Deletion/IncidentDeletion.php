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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Contracts\Deletion\DeletionContributorInterface;
use Uhifadhi\Contracts\Deletion\DeletionLine;
use Uhifadhi\Contracts\Deletion\DeletionSubject;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvent;
use Uhifadhi\Incident\Entity\IncidentEvidence;
use Uhifadhi\Incident\Entity\IncidentMoney;
use Uhifadhi\Incident\Entity\IncidentParty;

/**
 * AN INCIDENT, DELETED BY A SUPER ADMIN (ruled 28 Sep, #48): its history, its
 * evidence (rows and bytes), the parties and the money on it go with it; the
 * record it was filed from, if any, is not touched.
 */
final readonly class IncidentDeletion implements DeletionContributorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $router,
        private IncidentFileRemover $files,
    ) {
    }

    public function supports(object $record): bool
    {
        return $record instanceof Incident;
    }

    public function describe(object $record): DeletionSubject
    {
        \assert($record instanceof Incident);
        $area = $record->getArea();

        return new DeletionSubject(
            kind: 'incident',
            reference: $record->getReference(),
            title: 'Incident '.$record->getReference().' · '.$record->getTitle(),
            summary: implode(' · ', array_filter([$record->getKind()->getLabel(), $record->getOccurredAt()?->format('D j M'), $area->getName()])),
            recordUrl: $this->router->generate('incident_show', ['uuid' => $area->getUuidString(), 'reference' => $record->getReference()]),
            afterUrl: $this->router->generate('incident_list', ['uuid' => $area->getUuidString()]),
            register: 'incidents',
        );
    }

    public function whatGoes(object $record): array
    {
        \assert($record instanceof Incident);
        $evidence = IncidentFileRemover::evidenceOf($record, $this->entityManager);
        $bytes = array_sum(array_map(static fn (IncidentEvidence $e): int => $e->getByteSize() ?? 0, $evidence));

        return array_values(array_filter([
            new DeletionLine('incidents', 1, singular: 'incident'),
            new DeletionLine('evidence files', \count($evidence), detail: $bytes > 0 ? round($bytes / 1048576, 1).' MB' : null, singular: 'evidence file'),
            new DeletionLine('parties', $this->count(IncidentParty::class, $record), singular: 'party'),
            new DeletionLine('money lines', $this->count(IncidentMoney::class, $record), singular: 'money line'),
            new DeletionLine('history lines', $this->count(IncidentEvent::class, $record), singular: 'history line'),
        ], static fn (DeletionLine $line): bool => $line->count > 0));
    }

    public function whatStays(object $record): array
    {
        \assert($record instanceof Incident);

        return $record->hasProvenance()
            ? [new DeletionLine('records it was filed from', 1, items: [[(string) $record->getSourceRecordLabel(), 'stays · untouched']], singular: 'record it was filed from')]
            : [];
    }

    public function delete(object $record): void
    {
        \assert($record instanceof Incident);
        $this->files->remove(IncidentFileRemover::evidenceOf($record, $this->entityManager));
        $this->entityManager->remove($record);
        $this->entityManager->flush();
    }

    /** @param class-string $entity */
    private function count(string $entity, Incident $incident): int
    {
        return (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(x) FROM %s x WHERE x.incident = :incident', $entity))
            ->setParameter('incident', $incident)->getSingleScalarResult();
    }
}
