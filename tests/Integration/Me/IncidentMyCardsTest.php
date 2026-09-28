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

namespace Uhifadhi\Incident\Tests\Integration\Me;

use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Me\MyCard;
use Uhifadhi\Contracts\Me\MyCardProviderInterface;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Me\IncidentMyCards;
use Uhifadhi\Incident\Service\IncidentTransitionService;
use Uhifadhi\Incident\Tests\Integration\Fixtures\CollectedMyCardProviders;
use Uhifadhi\Incident\Tests\Integration\Fixtures\FixedGrantVoter;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * WHAT THIS MODULE SHOWS A PERSON ABOUT THEMSELVES on their own dashboard —
 * ME·12, "Incidents I reported" (#19, option A ruled 28 Sep 2026) — read
 * against a real register.
 *
 * "I REPORTED" IS {@see Incident::getReportedBy()}: the account the report
 * form files under. Somebody else's report in the same area is never on the
 * card, however recent.
 *
 * THE MORNING IS MONDAY 28 SEPTEMBER 2026, 11:42.
 */
final class IncidentMyCardsTest extends IntegrationTestCase
{
    private const string NOW = '2026-09-28 11:42:00';

    public function testTheCardIsCollectedUnderTheTagTheFrameReads(): void
    {
        /** @var CollectedMyCardProviders $collected */
        $collected = self::getContainer()->get(CollectedMyCardProviders::class);

        self::assertContains(IncidentMyCards::class, $collected->classes());
        self::assertInstanceOf(MyCardProviderInterface::class, $this->cards());
    }

    /**
     * ONE CARD, IN THE ROW AT THE FOOT, between the patrol module's
     * observations (10) and the roster's leave (25).
     */
    public function testOneCardInTheRowBetweenObservationsAndLeave(): void
    {
        $reporter = $this->aUser(FixedGrantVoter::REPORTER_EMAIL, 'N', 'Lekishon');
        $this->signIn($reporter);

        $cards = $this->cards()->cardsFor((string) $reporter->getUuidString(), $this->now());

        self::assertCount(1, $cards);
        self::assertSame(MyCard::ROW, $cards[0]->slot);
        self::assertSame(20, $cards[0]->order);
        self::assertStringContainsString('<span class="tab">Incidents I reported<span class="src">', $cards[0]->html);
    }

    /**
     * THE PERSON'S OWN REPORTS, NEWEST FIRST, and the tab counts this month's.
     * Last month's report is listed and not counted; a colleague's is neither.
     */
    public function testTheRowsAreThePersonsOwnReportsNewestFirst(): void
    {
        $area = $this->anAreaWithKinds('North area');
        $reporter = $this->aUser(FixedGrantVoter::REPORTER_EMAIL, 'N', 'Lekishon');
        $colleague = $this->aUser('colleague@example.test', 'T', 'Ndosi');

        $older = $this->anIncident($area, 'snaring', 'Snare line lifted', new \DateTimeImmutable('2026-08-30 09:00:00'), $reporter);
        $friday = $this->anIncident($area, 'snaring', 'Snare found', new \DateTimeImmutable('2026-09-25 10:20:00'), $reporter);
        $theirs = $this->anIncident($area, 'crop-raiding', 'Maize lost', new \DateTimeImmutable('2026-09-28 07:00:00'), $colleague);
        $today = $this->anIncident($area, 'livestock-depredation', 'Cattle inside the boundary', new \DateTimeImmutable('2026-09-28 08:15:00'), $reporter);
        $this->em->flush();
        $this->em->clear();

        $this->signIn($this->aUser(FixedGrantVoter::REPORTER_EMAIL));
        $html = $this->html($reporter);

        self::assertStringContainsString('&middot; 2 this month', $html);
        self::assertStringNotContainsString($theirs->getReference(), $html);

        $positions = array_map(
            static fn (Incident $incident) => strpos($html, '<b>'.$incident->getReference().'</b>'),
            [$today, $friday, $older],
        );
        self::assertNotContains(false, $positions, 'each of the person\'s own reports has a row');
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'newest first');

        // The area's own word for what happened, in the row's lower case.
        self::assertStringContainsString('<b>'.$today->getReference().'</b> livestock depredation', $html);
    }

    /**
     * THE TIME IS THE READER'S. Every instant carries its machine value for
     * the frame to localise; this morning's says "today".
     */
    public function testEachRowSaysWhenInTheReadersOwnTime(): void
    {
        $area = $this->anAreaWithKinds('North area');
        $reporter = $this->aUser(FixedGrantVoter::REPORTER_EMAIL, 'N', 'Lekishon');
        $this->anIncident($area, 'livestock-depredation', 'Cattle inside the boundary', new \DateTimeImmutable('2026-09-28 08:15:00'), $reporter);
        $this->anIncident($area, 'snaring', 'Snare found', new \DateTimeImmutable('2026-09-25 10:20:00'), $reporter);
        $this->em->flush();
        $this->em->clear();

        $this->signIn($this->aUser(FixedGrantVoter::REPORTER_EMAIL));
        $html = $this->html($reporter);

        self::assertMatchesRegularExpression('#today <time datetime="2026-09-28T08:15:00[^"]*" data-localtime-format="clock">08:15</time>#', $html);
        self::assertMatchesRegularExpression('#<time datetime="2026-09-25T10:20:00[^"]*" data-localtime-format="daystamp">fri 25 sep · 10:20</time>#', $html);
    }

    /** Open work is read against its term; finished work says where it got to. */
    public function testTheChipSpeaksTheModulesOwnStates(): void
    {
        $area = $this->anAreaWithKinds('North area');
        $reporter = $this->aUser(FixedGrantVoter::REPORTER_EMAIL, 'N', 'Lekishon');
        $this->anIncident($area, 'livestock-depredation', 'Cattle inside the boundary', new \DateTimeImmutable('2026-09-28 08:15:00'), $reporter);
        $finished = $this->anIncident($area, 'snaring', 'Snare found', new \DateTimeImmutable('2026-09-25 10:20:00'), $reporter);
        $this->moved($finished, [
            '2026-09-25 12:00:00' => IncidentTransitionEnum::Verify,
            '2026-09-25 13:00:00' => IncidentTransitionEnum::Respond,
            '2026-09-26 09:00:00' => IncidentTransitionEnum::Resolve,
        ]);
        $this->em->flush();
        $this->em->clear();

        $this->signIn($this->aUser(FixedGrantVoter::REPORTER_EMAIL));
        $html = $this->html($reporter);

        self::assertStringContainsString('<span class="chip ok">open · in term</span>', $html);
        self::assertStringContainsString('<span class="chip idle">resolved</span>', $html);
    }

    /** Nobody's first day is an empty box: one quiet line says so. */
    public function testSomebodyWhoHasReportedNothingIsToldSoInOneQuietLine(): void
    {
        $this->anAreaWithKinds('North area');
        $reporter = $this->aUser(FixedGrantVoter::REPORTER_EMAIL, 'N', 'Lekishon');
        $this->signIn($reporter);

        $html = $this->html($reporter);

        self::assertStringContainsString('&middot; 0 this month', $html);
        self::assertSame(1, substr_count($html, 'class="rln"'));
        self::assertStringContainsString('No incidents reported yet.', $html);
    }

    /** Bounded, like every dashboard card: the newest four, whatever the history. */
    public function testTheCardIsBounded(): void
    {
        $area = $this->anAreaWithKinds('North area');
        $reporter = $this->aUser(FixedGrantVoter::REPORTER_EMAIL, 'N', 'Lekishon');
        for ($day = 10; $day <= 15; ++$day) {
            $this->anIncident($area, 'snaring', 'Snare found', new \DateTimeImmutable(\sprintf('2026-09-%02d 09:00:00', $day)), $reporter);
        }
        $this->em->flush();
        $this->em->clear();

        $this->signIn($this->aUser(FixedGrantVoter::REPORTER_EMAIL));
        $html = $this->html($reporter);

        self::assertSame(4, substr_count($html, 'class="rln"'));
        self::assertStringContainsString('&middot; 6 this month', $html);
    }

    /**
     * THE DOOR IS DRAWN FOR SOMEBODY WHO MAY FILE, and leads to the report
     * form of the area they last reported in.
     */
    public function testTheDoorLeadsToTheReportFormWhereThePersonLastReported(): void
    {
        // Named so the register lists the other one first: the door follows
        // the person, not the alphabet.
        $this->anAreaWithKinds('East area');
        $second = $this->anAreaWithKinds('West area');
        $reporter = $this->aUser(FixedGrantVoter::REPORTER_EMAIL, 'N', 'Lekishon');
        $this->anIncident($second, 'snaring', 'Snare found', new \DateTimeImmutable('2026-09-25 10:20:00'), $reporter);
        $this->em->flush();

        $this->signIn($reporter);
        $html = $this->html($reporter);

        self::assertStringContainsString(
            \sprintf('<a class="tgl" href="/areas/%s/modules/incidents/new">Report one &rarr;</a>', $second->getUuidString()),
            $html,
        );
    }

    /** Somebody who has reported nothing yet is sent to the first area they may file in. */
    public function testWithNoReportYetTheDoorLeadsToTheFirstAreaThePersonMayFileIn(): void
    {
        $first = $this->anAreaWithKinds('North area');
        $reporter = $this->aUser(FixedGrantVoter::REPORTER_EMAIL, 'N', 'Lekishon');
        $this->signIn($reporter);

        self::assertStringContainsString(
            \sprintf('href="/areas/%s/modules/incidents/new"', $first->getUuidString()),
            $this->html($reporter),
        );
    }

    /** A person who may not file is never handed a door that answers 403. */
    public function testNoDoorForSomebodyWhoMayNotFile(): void
    {
        $area = $this->anAreaWithKinds('North area');
        $clerk = $this->aUser(FixedGrantVoter::CLERK_EMAIL, 'A', 'Sanka');
        $this->anIncident($area, 'snaring', 'Snare found', new \DateTimeImmutable('2026-09-25 10:20:00'), $clerk);
        $this->em->flush();

        $this->signIn($clerk);
        $html = $this->html($clerk);

        self::assertStringNotContainsString('Report one', $html);
        self::assertStringNotContainsString('sxfoot', $html);
    }

    /** And none with nobody signed in to ask about: the door fails closed. */
    public function testNoDoorWithNobodySignedIn(): void
    {
        $this->anAreaWithKinds('North area');
        $reporter = $this->aUser(FixedGrantVoter::REPORTER_EMAIL, 'N', 'Lekishon');

        self::assertStringNotContainsString('Report one', $this->html($reporter));
    }

    /** A uuid that names nobody has reported nothing; it is not an error. */
    public function testAUuidThatNamesNobodyReadsAsNothingReported(): void
    {
        $html = $this->cards()->cardsFor('not-a-uuid', $this->now())[0]->html;

        self::assertStringContainsString('No incidents reported yet.', $html);
    }

    private function html(User $person): string
    {
        $cards = $this->cards()->cardsFor((string) $person->getUuidString(), $this->now());
        self::assertCount(1, $cards);

        return $cards[0]->html;
    }

    private function cards(): IncidentMyCards
    {
        $cards = $this->service('incident.my_cards');
        self::assertInstanceOf(IncidentMyCards::class, $cards);

        return $cards;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    /** @param array<string, IncidentTransitionEnum> $moves when => which move */
    private function moved(Incident $incident, array $moves): void
    {
        $transitions = $this->service('incident.transitions');
        self::assertInstanceOf(IncidentTransitionService::class, $transitions);

        foreach ($moves as $at => $move) {
            $this->em->persist($transitions->apply($incident, $move, new \DateTimeImmutable($at), actorName: 'J. Mollel'));
        }
        $this->em->flush();
    }
}
