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

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Incident\Entity\IncidentMoney;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Service\IncidentTransitionService;

/**
 * THE CASE FILE, rendered — and the two contracts the design will not bend on.
 *
 * 1. GATED PANELS. A step owns a panel, and the panel does not EXIST until the
 *    step is reached. Not rendered-and-disabled. Absent. These tests read the
 *    DOM, because "absent" is a claim about the document and nothing else can
 *    prove it.
 * 2. THE RAIL, NOT A DROPDOWN. Only the legal moves are offered, and the ones
 *    that are not are shown with the reason printed beside them.
 */
final class CaseFilePageTest extends FunctionalTestCase
{
    private function transitions(): IncidentTransitionService
    {
        /** @var IncidentTransitionService $transitions */
        $transitions = static::getContainer()->get('test_public.incident.transitions');

        return $transitions;
    }

    public function testTheWholeRecordIsOnOnePage(): void
    {
        $area = $this->anAreaWithKinds();
        $this->aZone($area, 'North Gate');
        $incident = $this->anIncident($area, reportedBy: $this->aReporter());
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.pg', $incident->getReference());
        /*
         * The design's sections, all of them, on one page — named by the words
         * in their tab rather than by the workshop's reference for the frame.
         * The reference lives in the design files and never in the markup, so
         * reading it here would have been reading the one thing a warden cannot
         * see. The heading IS what they see, and it is what has to be there.
         */
        $tabs = $crawler->filter('span.tab')->each(static fn ($tab) => $tab->text());
        foreach (['Where', 'Timeline', 'Involved parties', 'Provenance & links', 'Evidence', 'Narrative'] as $section) {
            self::assertNotEmpty(
                array_filter($tabs, static fn (string $tab) => str_starts_with($tab, $section)),
                \sprintf('Section “%s” is missing.', $section),
            );
        }

        // The record's identity facts live on the shared .factband below the head
        // (the settled page-chrome theme, as every other entity-detail screen wears),
        // not in a sidebar "Incident meta" card.
        self::assertSelectorExists('.factband');
        self::assertSelectorTextContains('.factband', $incident->getReference());
    }

    /**
     * THE WAY BACK IS THE SHELL'S PILL.
     *
     * `.backbtn` is the settled cross-module idiom for the way back off a
     * record — the shell ships it, so every module's case file returns to its
     * list wearing the same control. The rule is `.backbtn`, never a `.tgl` and
     * never a page action; the sheet that defines it is the shell's, so this
     * module's own sheet stays free of a copy.
     */
    public function testTheWayBackWearsTheShellsOwnControl(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        $back = $crawler->filter('a.backbtn:contains("All incidents")');
        self::assertCount(1, $back, 'The way back has to carry the shell\'s .backbtn, or it renders as a bare hyperlink.');
        self::assertGreaterThan(0, $back->filter('svg')->count(), 'And the shell\'s own chevron beside the words.');
        self::assertCount(0, $crawler->filter('a.tgl:contains("All incidents")'), 'And it is the pill, not the toggle control it wore before the shell shipped one.');
    }

    /** An incident of another area is a 404 — the same answer as one that never existed. */
    public function testAnIncidentFromAnotherAreaIsNotFound(): void
    {
        $mine = $this->anAreaWithKinds('Mine');
        $theirs = $this->anAreaWithKinds('Theirs');
        $incident = $this->anIncident($theirs);
        $this->client->loginUser($this->aReporter());

        $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($mine),
            $incident->getReference(),
        ));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * THE GATE. A freshly reported incident has step one's panel and NOTHING
     * ELSE — no empty resolution form inviting somebody to fill it in early.
     */
    public function testAnUnreachedStepHasNoPanelAtAll(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area);
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        $html = $crawler->html();
        self::assertStringContainsString('Step 1 —', $html, 'Step one has been reached and must have its panel.');
        self::assertStringNotContainsString('Step 2 —', $html, 'Verification has not happened; its panel must not exist.');
        self::assertStringNotContainsString('Step 3 —', $html);
        self::assertStringNotContainsString('Step 4 —', $html);
    }

    /** …and the panel APPEARS once the step is reached, because it is the same rule read forwards. */
    public function testAPanelAppearsOnlyWhenItsStepIsReached(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area);
        $this->transitions()->apply($incident, IncidentTransitionEnum::Verify, new \DateTimeImmutable(), null, 'S. Laizer');
        $this->em->flush();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        $html = $crawler->html();
        self::assertStringContainsString('Step 1 —', $html);
        self::assertStringContainsString('Step 2 —', $html);
        // The next one is still not reached, and so still does not exist.
        self::assertStringNotContainsString('Step 3 —', $html);
    }

    /**
     * ONLY THE LEGAL MOVES ARE OFFERED, and the illegal ones are shown as the
     * reason they are illegal — never as a button that does nothing.
     */
    public function testOnlyTheLegalMovesAreOfferedAndTheRestSayWhyNot(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area);
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        // From `reported` there is exactly one move, and it is Verify.
        $buttons = $crawler->filter('.i-trans form button');
        self::assertCount(1, $buttons);
        self::assertStringContainsString('Verify', $buttons->text());

        // And exactly ONE refusal is printed: the move no person may ever make.
        // The rest are only "not that step's turn yet", which the rail above
        // already says by drawing those steps as unreached.
        $blocked = $crawler->filter('.i-trans .i-blocked');
        self::assertCount(1, $blocked);
        // The design's own words for it: "Close — only reachable from resolved".
        self::assertStringContainsString('Close', $blocked->text());
        self::assertStringContainsString('only reachable from resolved', $blocked->text());
    }

    /**
     * …and once it IS resolved, the refusal changes to the real reason: the clock
     * reaches `closed`, and no person ever does.
     */
    public function testOnAResolvedIncidentTheRefusalIsTheClockItself(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area, 'natural-mortality', 'Wildebeest carcass, no injury pattern');
        $at = new \DateTimeImmutable();
        foreach ([IncidentTransitionEnum::Verify, IncidentTransitionEnum::Respond, IncidentTransitionEnum::Resolve] as $step) {
            $this->transitions()->apply($incident, $step, $at = $at->modify('+1 hour'));
        }
        $this->em->flush();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        $blocked = $crawler->filter('.i-trans .i-blocked')->text();
        self::assertStringContainsString('reached by time, not by a person', $blocked);
        self::assertCount(0, $crawler->filter('.i-trans form button'), 'Nobody may close an incident by hand.');
    }

    /**
     * A RESOLVED INCIDENT SAYS WHEN IT WILL CLOSE ITSELF — the computed date, on
     * the rail, as a hint and not a control. Closing is the clock's move; the case
     * file's job is to tell the reader when, so nobody goes hunting for a button.
     */
    public function testAResolvedIncidentShowsItsAutomaticCloseDate(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area, 'natural-mortality', 'Wildebeest carcass, no injury pattern');
        $at = new \DateTimeImmutable('2026-08-21 10:00:00');
        foreach ([IncidentTransitionEnum::Verify, IncidentTransitionEnum::Respond, IncidentTransitionEnum::Resolve] as $step) {
            $this->transitions()->apply($incident, $step, $at = $at->modify('+1 hour'));
        }
        $this->em->flush();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $incident->getReference()));

        // Resolved 21 aug 13:00, closes 30 days later — the rail prints that date.
        $expected = $incident->getResolvedAt()?->modify('+30 days')->format('j M Y');
        self::assertNotNull($expected);
        $rail = $crawler->filter('.i-wf')->text();
        self::assertStringContainsString('auto-closes '.$expected, $rail);
        self::assertStringContainsString('closing is automatic', $rail);
    }

    /** Somebody without `incidents.manage` sees the rail and is offered no move at all. */
    public function testAReporterSeesTheRailAndIsOfferedNoMoves(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('.i-wf .i-wfstep'), 'The rail always draws all five places.');
        self::assertCount(0, $crawler->filter('.i-trans form button'));
    }

    /**
     * THE MONEY CARD APPEARS WHEN THERE IS A FIGURE — absent otherwise, not empty
     * and not greyed.
     *
     * Note what that is NOT: it is not "the category carries money". A word whose
     * money block asked a figure at filing has a card showing that figure and
     * saying nothing has been judged; a word that asked none, and that nobody has
     * put a figure on, has no card at all — and a word carrying no money block
     * never grows one.
     */
    public function testTheMoneyCardAppearsOnlyWhenThereIsMoney(): void
    {
        $area = $this->anAreaWithKinds();
        $claim = $this->anIncident($area, 'livestock-depredation');
        $mortality = $this->anIncident($area, 'natural-mortality', 'Wildebeest carcass, no injury pattern');
        $this->client->loginUser($this->aManager());

        // Nothing judged yet: the card carries the figure the money block asked at
        // filing, and says as much.
        $before = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $claim->getReference()));
        self::assertStringContainsString('claimed at filing', $before->filter('.i-moneyblock')->text());
        self::assertStringNotContainsString('assessed', $before->filter('.i-moneyblock')->text());

        // Somebody opens a claim — and the card is there.
        new IncidentMoney($claim, MoneyDirectionEnum::Compensation)
            ->setClaimed(1_600_000)->setAssessed(1_200_000)->setApproved(1_200_000);
        $this->em->flush();

        $after = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $claim->getReference()));
        self::assertCount(1, $after->filter('.i-moneyblock'));
        self::assertStringContainsString('1,200,000', $after->html());

        // A category that carries no money never grows one.
        $without = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $mortality->getReference()));
        self::assertCount(0, $without->filter('.i-moneyblock'));
    }

    /**
     * THE WORD'S BLOCKS DECIDE THE QUESTIONS. A depredation asks the species, a
     * count and who was on the record; a roadkill asks the species, the condition
     * and which named place. Same page, same component, different questions —
     * and neither of them can ask one its blocks do not.
     */
    public function testTheQuestionsAreTheBlocksTheWordSwitchedOn(): void
    {
        $area = $this->anAreaWithKinds();
        $depredation = $this->anIncident($area, 'livestock-depredation');
        $roadkill = $this->anIncident($area, 'roadkill', 'Zebra roadkill on a district road');
        $this->client->loginUser($this->aManager());

        $first = self::questionsOn($this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $depredation->getReference())));
        self::assertStringContainsString('Counts', $first);
        self::assertStringContainsString('head of stock', $first);
        self::assertStringContainsString('A stock owner', $first);
        self::assertStringNotContainsString('Condition & disposition', $first);

        $second = self::questionsOn($this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $roadkill->getReference())));
        self::assertStringContainsString('Condition & disposition', $second);
        self::assertStringContainsString('dead, fresh', $second);
        self::assertStringContainsString('road segment', $second);
        self::assertStringNotContainsString('Parties', $second);
    }

    /** Every question panel on a case file, as one string — a word has several. */
    private static function questionsOn(Crawler $page): string
    {
        return implode(' ', $page->filter('.i-fieldset')->each(static fn (Crawler $node): string => $node->text()));
    }

    /**
     * THE FIGURE ASKED AT FILING IS SHOWN AS WHAT IT IS, and it does not make a
     * money record: the card says "claimed at filing" and says out loud that
     * nothing has been judged.
     */
    public function testTheFigureAskedAtFilingShowsOnTheMoneyCardWithoutAMoneyRecord(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area, 'livestock-depredation');
        $this->client->loginUser($this->aManager());

        $page = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $incident->getReference()));

        self::assertNull($incident->getMoney());
        $card = $page->filter('.i-moneyblock')->text();
        self::assertStringContainsString('claimed at filing', $card);
        self::assertStringContainsString('900,000', $card);
        self::assertStringContainsString('nothing judged yet', $card);
    }

    /**
     * THE CASE FILE IS ITS FACTS STRIP, THE INDEX AND ONE COLUMN. Each index
     * row takes the reader to one card, in the cards' own order: the record,
     * then under a head the steps reached so far and the category's fields.
     */
    public function testTheCaseFileIsAnIndexOverOneColumnOfCards(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area, 'livestock-depredation');
        new IncidentMoney($incident, MoneyDirectionEnum::Compensation)->setClaimed(1_600_000)->setApproved(1_200_000);
        $this->em->flush();
        $this->client->loginUser($this->aReporter());

        $page = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        self::assertCount(0, $page->filter('.recgrid'));
        self::assertSame(
            ['#where', '#narrative', '#timeline', '#evidence', '#money', '#parties', '#provenance', '#step-reported', '#category'],
            $page->filter('.onpage-nav .onpage-row')->each(static fn (Crawler $row): ?string => $row->attr('href')),
        );
        self::assertSame(
            ['Where', 'Narrative', 'Timeline', 'Evidence', 'Money', 'Involved parties', 'Provenance & links', 'Reported', 'Category fields'],
            $page->filter('.onpage-nav .onpage-l')->each(static fn (Crawler $l): string => $l->text()),
        );
        self::assertCount(0, $page->filter('.onpage-nav p.onpage-h')->slice(1), 'The index carries no head: every row is a card, and the step rows follow the record rows.');
        self::assertSame(
            (string) \count($incident->getEvents()),
            $page->filter('.onpage-nav a[href="#timeline"] .onpage-n')->text(),
        );

        self::assertSame(
            ['where', 'narrative', 'timeline', 'evidence', 'money', 'parties', 'provenance', 'step-reported', 'category'],
            $page->filter('.onpage-main > [id]')->each(static fn (Crawler $c): ?string => $c->attr('id')),
        );
        self::assertCount(1, $page->filter('#where.plate-fill'), 'The plate leads the column.');
        self::assertSame('What this category asks for', $page->filter('.onpage-main h2.zone')->eq(1)->text());
        self::assertStringNotContainsString('behaviour block', $page->filter('.onpage-main')->text());
    }

    /**
     * A RECORD THAT CARRIES NO MONEY HAS NO MONEY CARD, and no index row for one.
     */
    public function testWithoutMoneyTheIndexHasNoMoneyRow(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area, 'natural-mortality', 'Wildebeest carcass, no injury pattern');
        $this->client->loginUser($this->aReporter());

        $page = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        self::assertSame(
            ['Where', 'Narrative', 'Timeline', 'Evidence', 'Involved parties', 'Provenance & links', 'Step 1 — reported'],
            self::cardsIn($page->filter('.onpage-main')),
        );
        self::assertCount(0, $page->filter('.onpage-nav a[href="#money"]'));
    }

    /**
     * The cards a grid cell holds, named by the words in their tab — a cell that
     * IS a card counts as itself.
     *
     * @return list<string>
     */
    private static function cardsIn(Crawler $cell): array
    {
        $tabs = $cell->filter('span.tab')->each(
            static fn (Crawler $tab) => trim(explode('·', $tab->text())[0]),
        );

        return array_values(array_filter($tabs, static fn (string $tab) => '' !== $tab));
    }

    /** The timeline is the spine, and the filing itself is its first entry. */
    public function testTheTimelineStartsWithTheFiling(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area, reportedBy: $this->aReporter());
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $incident->getReference()));

        self::assertCount(1, $crawler->filter('.i-tl .i-tl-item'));
        self::assertStringContainsString('Filed as', $crawler->filter('.i-tl')->text());
    }
}
