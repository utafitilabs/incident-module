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
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Contracts\Deletion\DeletionContributorInterface;
use Uhifadhi\Contracts\Deletion\DeletionLine;
use Uhifadhi\Contracts\Deletion\DeletionSubject;
use Uhifadhi\Contracts\Deletion\LinkedRecordsInterface;
use Uhifadhi\Incident\Entity\Incident;

/**
 * AN INCIDENT FILED FROM A RECORD THAT GOES STAYS, LOSING ONLY ITS LINK
 * (ruled 28 Sep, #48). The record names what goes by id; an incident filed
 * from one of them keeps its report and says the record was deleted.
 */
final readonly class IncidentLinksDeletion implements DeletionContributorInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function supports(object $record): bool
    {
        return $record instanceof LinkedRecordsInterface && [] !== $this->filedFrom($record);
    }

    public function describe(object $record): ?DeletionSubject
    {
        return null;
    }

    public function whatGoes(object $record): array
    {
        return [];
    }

    public function whatStays(object $record): array
    {
        \assert($record instanceof LinkedRecordsInterface);
        $incidents = $this->filedFrom($record);

        return [new DeletionLine('incidents filed from it', \count($incidents), items: array_map(
            static fn (Incident $i): array => [$i->getReference().' · '.$i->getTitle(), 'stays · loses its link'],
            $incidents,
        ), singular: 'incident filed from it')];
    }

    public function delete(object $record): void
    {
        \assert($record instanceof LinkedRecordsInterface);
        $now = new \DateTimeImmutable();
        foreach ($this->filedFrom($record) as $incident) {
            $incident->dropSourceLink($now);
        }
        $this->entityManager->flush();
    }

    /** @return list<Incident> */
    private function filedFrom(LinkedRecordsInterface $record): array
    {
        $uuids = array_values(array_filter(array_map(
            static fn (string $id): ?Uuid => Uuid::isValid($id) ? Uuid::fromString($id) : null,
            $record->linkedRecordUuids(),
        )));
        if ([] === $uuids) {
            return [];
        }

        /** @var list<Incident> $incidents */
        $incidents = $this->entityManager->createQuery(\sprintf('SELECT i FROM %s i WHERE i.sourceRecordUuid IN (:uuids)', Incident::class))
            ->setParameter('uuids', array_map(static fn (Uuid $u): string => $u->toRfc4122(), $uuids))
            ->getResult();

        return $incidents;
    }
}
