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

namespace Uhifadhi\Incident\Tests\Functional;

use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\TeamBundle\Entity\DeletionRecord;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Contracts\Deletion\LinkedRecordsInterface;
use Uhifadhi\Incident\Deletion\IncidentLinksDeletion;
use Uhifadhi\Incident\Deletion\PersonIncidentDeletion;
use Uhifadhi\Incident\Entity\Incident;

/**
 * A SUPER ADMIN DELETES AN INCIDENT (ruled 28 Sep, #48): counted on the core's
 * one delete page, the reference typed, one audit line kept. An incident filed
 * from a record that goes stays and loses only its link; a deleted person's
 * reports go with them.
 */
final class IncidentDeletionTest extends FunctionalTestCase
{
    public function testASuperAdminDeletesAnIncidentAndOneLineIsKept(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area);
        $this->signInAs(TeamRoleEnum::SuperAdmin);

        $page = $this->client->request('GET', $this->deleteUrl($area, $incident));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Delete incident '.$incident->getReference(), $page->filter('h1')->text());

        $this->client->submit($page->filter('form.dconfirm')->form(['reference' => $incident->getReference()]));

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame([], $this->em->getRepository(Incident::class)->findAll());
        $kept = $this->em->getRepository(DeletionRecord::class)->findAll();
        self::assertCount(1, $kept);
        self::assertStringStartsWith('Incident '.$incident->getReference(), $kept[0]->getTitle());
    }

    public function testAnAdminIsRefusedThePage(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area);
        $this->signInAs(TeamRoleEnum::Admin);

        $this->client->request('GET', $this->deleteUrl($area, $incident));

        self::assertResponseStatusCodeSame(403);
    }

    /** "An incident filed from a deleted patrol stays, losing only its link" (ruled 28 Sep). */
    public function testAnIncidentFiledFromARecordThatGoesStaysWithoutItsLink(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area);
        $observation = Uuid::v7();
        $incident->recordProvenance($observation, 'Observation 2 of P-0142', '/areas/x/modules/patrols/y');
        $this->em->flush();
        $record = new class((string) $observation) implements LinkedRecordsInterface {
            public function __construct(private readonly string $id)
            {
            }

            public function linkedRecordUuids(): array
            {
                return [$this->id];
            }
        };
        $links = static::getContainer()->get('incident.deletion.links');
        self::assertInstanceOf(IncidentLinksDeletion::class, $links);

        self::assertTrue($links->supports($record));
        self::assertSame([[$incident->getReference().' · '.$incident->getTitle(), 'stays · loses its link']], $links->whatStays($record)[0]->items);
        $links->delete($record);

        $this->em->clear();
        $stored = $this->em->getRepository(Incident::class)->findOneBy(['reference' => $incident->getReference()]);
        self::assertInstanceOf(Incident::class, $stored);
        self::assertNull($stored->getSourceRecordUrl());
        self::assertNotNull($stored->getSourceRecordDeletedAt());
        self::assertSame('Observation 2 of P-0142', $stored->getSourceRecordLabel());
    }

    public function testADeletedPersonsReportsGoWithThem(): void
    {
        $area = $this->anAreaWithKinds();
        $reporter = $this->aReporter();
        $this->anIncident($area, reportedBy: $reporter);
        $person = static::getContainer()->get('incident.deletion.person');
        self::assertInstanceOf(PersonIncidentDeletion::class, $person);

        self::assertSame('1 incident they reported', $person->whatGoes($reporter)[0]->phrase());
        $person->delete($reporter);

        $this->em->clear();
        self::assertSame([], $this->em->getRepository(Incident::class)->findAll());
    }

    private function signInAs(TeamRoleEnum $tier): void
    {
        $who = $this->aUser($tier->value.'@example.test', 'Naomi', 'Kileo')->setTeamRole($tier);
        $this->em->flush();
        $this->client->loginUser($who);
    }

    private function deleteUrl(\Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest $area, Incident $incident): string
    {
        return '/areas/'.$this->uuidOf($area).'/modules/incidents/'.$incident->getReference().'/delete';
    }
}
