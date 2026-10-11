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

namespace Uhifadhi\Incident\EventListener;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Symfony\Component\Messenger\MessageBusInterface;
use Uhifadhi\Contracts\Facts\FactPeriod;
use Uhifadhi\Contracts\Facts\RecomputeFacts;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Facts\IncidentFactProvider;

/**
 * A CHANGE TO WHAT THE FIGURES COUNT ASKS THE CORE TO FILE ITS MONTHS AGAIN.
 *
 * The core's schedule refiles only the periods still open, and a closed month
 * keeps what it last filed. An incident filed late, resolved, reopened,
 * re-filed under another sub-category or area, or deleted changes the figures
 * of the month it was reported in and of the month it was resolved in, so the
 * flush that wrote the change asks for both — {@see RecomputeFacts}, which the
 * worker answers.
 *
 * The months are collected while the flush is computed and sent once it has
 * been written, so a flush that fails asks for nothing.
 *
 * @see https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/events.html#onflush
 * @see https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/events.html#postflush
 */
final class IncidentFactsListener
{
    /** What an incident's figures are made of; a change to anything else asks for nothing. */
    private const array COUNTED = ['status', 'reportedAt', 'resolvedAt', 'subcategory', 'area'];

    /** @var array<string, true> month keys asked for by the flush under way */
    private array $months = [];

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        $work = $event->getObjectManager()->getUnitOfWork();

        foreach ([...$work->getScheduledEntityInsertions(), ...$work->getScheduledEntityDeletions()] as $entity) {
            if ($entity instanceof Incident) {
                $this->ask($entity->getReportedAt(), $entity->getResolvedAt());
            }
        }

        foreach ($work->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Incident) {
                continue;
            }

            $changes = $work->getEntityChangeSet($entity);
            if ([] === array_intersect(self::COUNTED, array_keys($changes))) {
                continue;
            }

            $this->ask($entity->getReportedAt(), $entity->getResolvedAt());
            $reported = $changes['reportedAt'][0] ?? null;
            $resolved = $changes['resolvedAt'][0] ?? null;
            $this->ask(
                $reported instanceof \DateTimeImmutable ? $reported : null,
                $resolved instanceof \DateTimeImmutable ? $resolved : null,
            );
        }
    }

    public function postFlush(PostFlushEventArgs $event): void
    {
        if ([] === $this->months) {
            return;
        }

        $months = array_keys($this->months);
        $this->months = [];

        $this->bus->dispatch(new RecomputeFacts(IncidentFactProvider::SLUG, $months));
    }

    private function ask(?\DateTimeImmutable ...$instants): void
    {
        foreach ($instants as $instant) {
            if (null !== $instant) {
                $this->months[FactPeriod::month($instant)->key] = true;
            }
        }
    }
}
