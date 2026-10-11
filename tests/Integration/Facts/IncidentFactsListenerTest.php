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

namespace Uhifadhi\Incident\Tests\Integration\Facts;

use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Facts\RecomputeFacts;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentMoney;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * A CHANGE TO WHAT THE FIGURES COUNT ASKS THE CORE TO FILE THEM AGAIN — the
 * month an incident was reported in and the month it was resolved in, since
 * the schedule refiles only the periods still open.
 */
final class IncidentFactsListenerTest extends IntegrationTestCase
{
    private AreaOfInterest $area;

    protected function setUp(): void
    {
        parent::setUp();
        $this->area = $this->anAreaWithKinds('North Sector');
    }

    public function testFilingAnIncidentAsksForItsMonth(): void
    {
        $this->clearQueue();
        $this->filed('2026-03-10 09:00:00');

        self::assertSame([['2026-03']], $this->asked());
    }

    public function testResolvingAnIncidentAsksForTheMonthItWasReportedInAndTheOneItWasResolvedIn(): void
    {
        $incident = $this->filed('2026-03-10 09:00:00');
        $this->clearQueue();

        $incident->setStatus(IncidentStatusEnum::Resolved)->setResolvedAt(new \DateTimeImmutable('2026-05-02 09:00:00'));
        $this->em->flush();

        self::assertSame([['2026-03', '2026-05']], $this->asked());
    }

    public function testDeletingAnIncidentAsksForItsMonth(): void
    {
        $incident = $this->filed('2026-03-10 09:00:00');
        $this->clearQueue();

        $this->em->remove($incident);
        $this->em->flush();

        self::assertSame([['2026-03']], $this->asked());
    }

    /** The money on an incident is a figure of the month it was reported in. */
    public function testChangingTheMoneyAsksForTheMonthTheIncidentWasReportedIn(): void
    {
        $incident = $this->filed('2026-03-10 09:00:00');
        $money = new IncidentMoney($incident, MoneyDirectionEnum::Compensation);
        $this->em->persist($money);
        $this->em->flush();
        $this->clearQueue();

        $money->setApproved(180000);
        $this->em->flush();

        self::assertSame([['2026-03']], $this->asked());
    }

    public function testAChangeNoFigureCountsAsksForNothing(): void
    {
        $incident = $this->filed('2026-03-10 09:00:00');
        $this->clearQueue();

        $incident->setTitle('Lion killed four goats near Riverside');
        $this->em->flush();

        self::assertSame([], $this->asked());
    }

    private function filed(string $at): Incident
    {
        $incident = $this->anIncident($this->area, at: new \DateTimeImmutable($at));
        $this->em->flush();

        return $incident;
    }

    /** @return list<list<string>> the months each queued recompute of this module asks for */
    private function asked(): array
    {
        $asked = [];
        foreach ($this->async()->all() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof RecomputeFacts && 'incidents' === $message->moduleSlug) {
                $keys = $message->monthKeys;
                sort($keys);
                $asked[] = $keys;
            }
        }

        return $asked;
    }

    private function clearQueue(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM messenger_messages');
    }

    private function async(): ListableReceiverInterface
    {
        $transport = $this->service('messenger.transport.async');
        self::assertInstanceOf(ListableReceiverInterface::class, $transport);

        return $transport;
    }
}
