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
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Service\IncidentTransitionService;
use Uhifadhi\Incident\Tests\Integration\Fixtures\FixedPermissionVoter;

/**
 * WITHHOLDING A FACT WITHOUT WITHHOLDING THE PAGE — the whole reason the case
 * file and the money are concerns of their own rather than sections of the
 * record's.
 *
 * The clerk here holds everything the ordinary reader holds EXCEPT
 * `case-money.read`. They open the same case file everybody else opens, read
 * the narrative, the parties and the evidence, and the money is simply not on
 * the page: not greyed, not a placeholder, absent — and absent from the
 * subline above it too, which is the half a summary line quietly gives back.
 *
 * ASSERTED IN BOTH DIRECTIONS, because a test that only proves something is
 * missing passes just as well when the whole page has failed to render.
 */
final class SensitiveConcernsTest extends FunctionalTestCase
{
    /** An incident far enough along to carry judged money, with money on it. */
    private function withMoney(AreaOfInterest $area): Incident
    {
        /** @var IncidentTransitionService $transitions */
        $transitions = static::getContainer()->get('test_public.incident.transitions');

        $incident = $this->anIncident($area);
        $at = new \DateTimeImmutable();
        $transitions->apply($incident, IncidentTransitionEnum::Verify, $at);
        $transitions->apply($incident, IncidentTransitionEnum::Respond, $at->modify('+1 hour'));
        $this->em->flush();

        $this->client->loginUser($this->aManager());
        $this->client->request('POST', \sprintf('/areas/%s/modules/incidents/%s/money', $this->uuidOf($area), $incident->getReference()), [
            '_token' => $this->csrfFor($area),
            'claimed' => '400000',
            'approved' => '250000',
            'settled' => '100000',
        ]);
        self::assertResponseRedirects();

        return $incident;
    }

    public function testTheMoneyIsWithheldFromSomebodyWhoReadsTheRestOfTheCase(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->withMoney($area);
        $url = \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $incident->getReference());

        // The ordinary reader, who holds case-money.read: the figures are there.
        $this->client->loginUser($this->aUser('reader@example.test', 'Neema', 'Kimaro'));
        $seen = $this->client->request('GET', $url)->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('250,000', $seen, 'the reader who holds case-money.read reads the approved amount.');

        // The clerk, who does not: the same page, one card short.
        $this->client->loginUser($this->aUser(FixedPermissionVoter::CLERK_EMAIL, 'Sara', 'Mushi'));
        $withheld = $this->client->request('GET', $url)->html();

        self::assertResponseIsSuccessful('a withheld fact must never withhold the page it sits on.');
        self::assertStringNotContainsString('250,000', $withheld, 'the approved amount reached somebody who may not read the money.');
        self::assertStringNotContainsString('100,000', $withheld, 'the settled amount reached somebody who may not read the money.');

        // …and the case file itself is still whole for them, which is the half
        // that makes this a withheld FACT rather than a withheld screen.
        self::assertStringContainsString('Involved parties', $withheld);
        self::assertStringContainsString('Narrative', $withheld);
        self::assertStringContainsString('Evidence', $withheld);
        self::assertStringContainsString($incident->getReference(), $withheld);
    }

    /** The gate behind the card refuses too — a hidden control is a courtesy. */
    public function testTheMoneyWriteRefusesSomebodyWhoMayNotReadIt(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->withMoney($area);

        $token = $this->csrfFor($area);
        $this->client->loginUser($this->aUser(FixedPermissionVoter::CLERK_EMAIL, 'Sara', 'Mushi'));
        $this->client->request('POST', \sprintf(
            '/areas/%s/modules/incidents/%s/money',
            $this->uuidOf($area),
            $incident->getReference(),
        ), ['_token' => $token, 'approved' => '999']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * THE AGGREGATES OBEY THE SAME DOOR AS THE RECORD.
     *
     * A register-wide total is the cases' figures added up, so drawing one
     * for somebody the cases are withheld from would hand back exactly what
     * the case files hold back. The KPI strip, the money board and the
     * register's money column all ask `case-money.read`; the evidence board
     * asks `case-files.read`.
     *
     * WITHHELD IS THE WORD, NOT A NOUGHT. A zero is a measurement, and this
     * is the absence of permission to take one.
     */
    public function testTheMoneyAggregatesOnTheAreaDashboardAreWithheld(): void
    {
        $area = $this->anAreaWithKinds();
        $this->withMoney($area);
        $url = \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area));

        // A reader who holds the money: the board and the strip carry it.
        $this->client->loginUser($this->aUser('reader@example.test', 'Neema', 'Kimaro'));
        $seen = $this->client->request('GET', $url)->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Fines &amp; compensation', $seen);
        self::assertStringContainsString('M TZS', $seen, 'the KPI strip carries the money headline for somebody who may read it.');

        // The clerk: the same dashboard, the money said to be withheld.
        $this->client->loginUser($this->aUser(FixedPermissionVoter::CLERK_EMAIL, 'Sara', 'Mushi'));
        $withheld = $this->client->request('GET', $url)->html();

        self::assertResponseIsSuccessful('a withheld figure must never withhold the dashboard it sits on.');
        self::assertStringNotContainsString('M TZS', $withheld, 'the money headline reached somebody who may not read the money.');
        self::assertStringNotContainsString('fines due', $withheld);
        self::assertStringContainsString('withheld', $withheld, 'a withheld figure says so; it is not a silent gap.');
        // The register is still there, one column short.
        self::assertStringContainsString('Open incidents', $withheld);
    }

    /**
     * THE ORGANIZATION DASHBOARD. This module's cell there publishes no money
     * and no case-file fact — it is references, areas, kinds and states — so
     * there is nothing on it to withhold, and the assertion is that the clerk
     * gets the cell whole rather than a hole where a figure was.
     */
    public function testTheOrganizationDashboardCarriesNoMoneyFigureToWithhold(): void
    {
        $area = $this->anAreaWithKinds();
        $this->withMoney($area);

        $this->client->loginUser($this->aUser(FixedPermissionVoter::CLERK_EMAIL, 'Sara', 'Mushi'));
        $html = $this->client->request('GET', '/')->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Latest incidents', $html);
        self::assertStringNotContainsString('M TZS', $html);
        self::assertStringNotContainsString('fines due', $html);
    }
}
