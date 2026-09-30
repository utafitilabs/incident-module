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

use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvidence;
use Uhifadhi\Storage\Service\EvidenceStorage;

/**
 * THE EVIDENCE BYTES A DELETED INCIDENT LEAVES IN THE FILE STORE. The database
 * removes the rows that name them; nothing but this removes the files.
 */
final readonly class IncidentFileRemover
{
    public function __construct(private EvidenceStorage $storage)
    {
    }

    /** @param list<IncidentEvidence> $evidence */
    public function remove(array $evidence): void
    {
        foreach ($evidence as $item) {
            if (null !== $item->getPath()) {
                $this->storage->delete($item->getPath());
            }
        }
    }

    /** @return list<IncidentEvidence> */
    public static function evidenceOf(Incident $incident, \Doctrine\ORM\EntityManagerInterface $em): array
    {
        /** @var list<IncidentEvidence> $rows */
        $rows = $em->getRepository(IncidentEvidence::class)->findBy(['incident' => $incident]);

        return $rows;
    }
}
