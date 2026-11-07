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

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Service\IncidentTransitionService;

/**
 * THE MONEY WRITE SURFACE, over HTTP — the panel the read-only card was always
 * waiting for, and the two contracts it shares with every other panel on the case
 * file: it is GATED (absent until `in progress`, absent where the category carries
 * no money) and it is GUARDED (`case-money.manage`, CSRF, area-scoped).
 *
 * The story runs end to end: the panel appears, the first save creates the row and
 * lights the card, settling the money unlocks the Resolve move the guard was
 * holding shut.
 */
final class MoneyPanelTest extends FunctionalTestCase
{
    private function transitions(): IncidentTransitionService
    {
        /** @var IncidentTransitionService $transitions */
        $transitions = static::getContainer()->get('test_public.incident.transitions');

        return $transitions;
    }

    private function inProgress(AreaOfInterest $area, string $subcategory = 'livestock-depredation'): Incident
    {
        $incident = $this->anIncident($area, $subcategory);
        $at = new \DateTimeImmutable();
        $this->transitions()->apply($incident, IncidentTransitionEnum::Verify, $at);
        $this->transitions()->apply($incident, IncidentTransitionEnum::Respond, $at->modify('+1 hour'));
        $this->em->flush();

        return $incident;
    }

    private function verified(AreaOfInterest $area, string $subcategory): Incident
    {
        $incident = $this->anIncident($area, $subcategory);
        $this->transitions()->apply($incident, IncidentTransitionEnum::Verify, new \DateTimeImmutable());
        $this->em->flush();

        return $incident;
    }

    private function show(AreaOfInterest $area, Incident $incident): string
    {
        return $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $incident->getReference()))->html();
    }

    private function moneyUrl(AreaOfInterest $area, Incident $incident, string $suffix = ''): string
    {
        return \sprintf('/areas/%s/modules/incidents/%s/money%s', $this->uuidOf($area), $incident->getReference(), $suffix);
    }

    /** GATED: no write panel before response has started. */
    public function testThePanelIsAbsentBeforeInProgress(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area);
        $this->client->loginUser($this->aManager());

        self::assertStringNotContainsString('i-moneyedit', $this->show($area, $incident));
    }

    /** GATED: no write panel where the sub-category carries no money, even at in progress. */
    public function testThePanelIsAbsentWhereTheCategoryCarriesNoMoney(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->inProgress($area, 'natural-mortality');
        $this->client->loginUser($this->aManager());

        self::assertStringNotContainsString('i-moneyedit', $this->show($area, $incident));
    }

    /** …and it appears at in progress on a category that does carry money. */
    public function testThePanelAppearsAtInProgressOnACategoryThatCarriesMoney(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->inProgress($area);
        $this->client->loginUser($this->aManager());

        $html = $this->show($area, $incident);
        self::assertStringContainsString('i-moneyedit', $html);
        // The direction rides as a fixed heading, in the module's own words — the
        // authority, never a named client.
        self::assertStringContainsString('owed BY the authority', $html);
    }

    /**
     * GATED PER DIRECTION: a COMPENSATION claim is taken from `verified`, so the
     * panel is already there — the same rule the service enforces, drawn.
     */
    public function testTheCompensationPanelAppearsAtVerified(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->verified($area, 'livestock-depredation');
        $this->client->loginUser($this->aManager());

        $html = $this->show($area, $incident);
        self::assertStringContainsString('i-moneyedit', $html);
        self::assertStringContainsString('owed BY the authority', $html);
    }

    /** …and a FINE is not: enforcement starts at `in progress`, so the panel waits. */
    public function testTheFinePanelIsStillShutAtVerified(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->verified($area, 'illegal-grazing');
        $this->client->loginUser($this->aManager());

        self::assertStringNotContainsString('i-moneyedit', $this->show($area, $incident));
    }

    /** A reporter, who cannot manage, is never shown the write panel. */
    public function testAReporterNeverSeesTheWritePanel(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->inProgress($area);
        $this->client->loginUser($this->aReporter());

        self::assertStringNotContainsString('i-moneyedit', $this->show($area, $incident));
    }

    /** THE FIRST SAVE CREATES THE ROW and lights the read-only card. */
    public function testRecordingMoneyCreatesTheRowAndTheCardAppears(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->inProgress($area);
        $this->client->loginUser($this->aManager());

        // No row yet — the card is there, carrying the figure the money block
        // asked at filing and nothing anybody has judged.
        self::assertStringContainsString('claimed at filing', $this->show($area, $incident));
        self::assertStringContainsString('nothing judged yet', $this->show($area, $incident));

        $this->client->request('POST', $this->moneyUrl($area, $incident), [
            '_token' => $this->csrfFor($area),
            'claimed' => '1600000',
            'assessed' => '1200000',
            'approved' => '1200000',
            'settled' => '0',
        ]);
        self::assertResponseRedirects();

        $this->em->clear();
        $stored = $this->em->getRepository(Incident::class)->findOneBy(['reference' => $incident->getReference()]);
        self::assertNotNull($stored);
        $money = $stored->getMoney();
        self::assertNotNull($money);
        self::assertSame(1_200_000, $money->outstanding());

        $after = $this->show($area, $incident);
        self::assertStringContainsString('i-moneyblock', $after);
        self::assertStringContainsString('1,200,000', $after);
    }

    /** THE WAIVE PATH — money given up on, with a reason, over HTTP. */
    public function testWaivingMoneyWithAReason(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->inProgress($area);
        $this->client->loginUser($this->aManager());

        $this->client->request('POST', $this->moneyUrl($area, $incident, '/waive'), [
            '_token' => $this->csrfFor($area),
            'reason' => 'Household withdrew the claim in writing.',
        ]);
        self::assertResponseRedirects();

        $this->em->clear();
        $money = $this->em->getRepository(Incident::class)->findOneBy(['reference' => $incident->getReference()])?->getMoney();
        self::assertNotNull($money);
        self::assertTrue($money->isWaived());
    }

    /** A waiver with no reason is refused — a waiver without a reason is a deletion. */
    public function testAWaiverWithNoReasonIs422(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->inProgress($area);
        $this->client->loginUser($this->aManager());

        $this->client->request('POST', $this->moneyUrl($area, $incident, '/waive'), [
            '_token' => $this->csrfFor($area),
            'reason' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /** RESOLVE UNLOCKS ONCE THE MONEY IS SETTLED — the guard the whole panel exists to satisfy. */
    public function testSettlingTheMoneyUnlocksResolve(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->inProgress($area);
        $this->client->loginUser($this->aManager());

        // Before any money is recorded, the money card is not the blocker — but as
        // soon as an outstanding claim is opened, Resolve is held shut.
        $this->client->request('POST', $this->moneyUrl($area, $incident), [
            '_token' => $this->csrfFor($area),
            'assessed' => '1200000',
            'approved' => '1200000',
            'settled' => '0',
        ]);
        self::assertResponseRedirects();

        $blockedHtml = $this->show($area, $incident);
        self::assertStringContainsString('outstanding', $blockedHtml, 'An unpaid claim holds Resolve shut.');

        // Settle it — and the Resolve move the guard was holding is now offered.
        $this->client->request('POST', $this->moneyUrl($area, $incident), [
            '_token' => $this->csrfFor($area),
            'assessed' => '1200000',
            'approved' => '1200000',
            'settled' => '1200000',
        ]);
        self::assertResponseRedirects();

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $incident->getReference()));
        $moves = $crawler->filter('.i-jr-s.now .i-jr-acts form button')->each(static fn ($b) => $b->text());
        self::assertNotEmpty(
            array_filter($moves, static fn (string $m) => str_contains($m, 'Mark resolved')),
            'Once the money is settled, Resolve is offered.',
        );

        // …and the move actually goes through now.
        $this->client->request('POST', \sprintf(
            '/areas/%s/modules/incidents/%s/transition/%s',
            $this->uuidOf($area),
            $incident->getReference(),
            IncidentTransitionEnum::Resolve->value,
        ), ['_token' => $this->csrfFor($area)]);
        self::assertResponseRedirects();

        $this->em->clear();
        self::assertSame(
            IncidentStatusEnum::Resolved,
            $this->em->getRepository(Incident::class)->findOneBy(['reference' => $incident->getReference()])?->getStatus(),
        );
    }

    /** Recording money needs `case-money.manage` — a reporter is refused. */
    public function testAReporterCannotRecordMoney(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->inProgress($area);
        $this->client->loginUser($this->aManager());
        $token = $this->csrfFor($area);

        $this->client->loginUser($this->aReporter());
        $this->client->request('POST', $this->moneyUrl($area, $incident), [
            '_token' => $token,
            'assessed' => '1000000',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    /** A money write with no token is refused, whoever is signed in. */
    public function testAMoneyWriteWithoutACsrfTokenIsRefused(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->inProgress($area);
        $this->client->loginUser($this->aManager());

        $this->client->request('POST', $this->moneyUrl($area, $incident), ['assessed' => '1000000']);

        self::assertResponseStatusCodeSame(403);
    }

    /** Posting money before response has started is refused, even directly. */
    public function testRecordingBeforeInProgressIs422(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area); // still reported
        $this->client->loginUser($this->aManager());

        $this->client->request('POST', $this->moneyUrl($area, $incident), [
            '_token' => $this->csrfFor($area),
            'assessed' => '1000000',
        ]);

        self::assertResponseStatusCodeSame(422);
    }
}
