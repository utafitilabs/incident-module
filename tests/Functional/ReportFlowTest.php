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
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingChange;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingsWriter;
use Uhifadhi\Contracts\Settings\SettingDepth;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\IncidentSourceEnum;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Settings\PlacementSettings;
use Uhifadhi\Incident\Tests\Integration\Fixtures\StubRecordFileSource;

/**
 * FILING AN INCIDENT, over HTTP.
 *
 * The design's own economics are the thing under test: a report is CHEAP and a
 * verification is EXPENSIVE. What makes the record what it is files an incident —
 * a kind, one line saying what happened, a place, and the defining question of
 * every block the category switches on — and the paperwork is offered on the
 * record afterwards.
 *
 * ONE CONTAINER, WHATEVER THE ENTRY POINT — the ruled direction (A, the full
 * page, with D's quick-file discipline inside it). A filing gets an ADDRESS: it
 * survives a reload, a dropped connection, a second tab and a pasted link, it
 * prints, and no click beside it can throw a half-written report away. The
 * slide-over drawer the module used to open when a filing arrived from a record
 * is retired; a filing FROM a record renders the same full page, with the source
 * card riding at its head. Both entry points render the same step partials, gate
 * the same answers, and post to the same endpoint.
 */
final class ReportFlowTest extends FunctionalTestCase
{
    /** The provenance a filing arriving from a patrol observation carries. */
    private const array FROM_A_RECORD = [
        'source' => 'patrol_observation',
        'label' => 'observation 2 of patrol P-0142',
        'back' => '/areas/x/modules/patrols/observation/2',
        'at' => '2026-08-22T08:15:00+03:00',
        'lat' => '-3.2014',
        'lng' => '-29.5378',
        'note' => 'Fresh lion tracks 400 m from the bomas.',
    ];

    private function reportUrl(string $areaUuid): string
    {
        return \sprintf('/areas/%s/modules/incidents/new', $areaUuid);
    }

    private function createUrl(string $areaUuid): string
    {
        return \sprintf('/areas/%s/modules/incidents', $areaUuid);
    }

    /** A filing from the record the fixture module actually holds photographs for. */
    private function fromAStubbedRecordUrl(string $areaUuid): string
    {
        // array_merge, not `+`: a union keeps the LEFT side's keys, so the
        // source token below would be silently ignored.
        return $this->reportUrl($areaUuid).'?'.http_build_query(array_merge(
            self::FROM_A_RECORD,
            [
                'record' => StubRecordFileSource::RECORD,
                'source' => StubRecordFileSource::TOKEN,
            ],
        ));
    }

    /** The same route, carrying a record — which is what opens the drawer. */
    private function fromARecordUrl(string $areaUuid, ?string $record = null): string
    {
        return $this->reportUrl($areaUuid).'?'.http_build_query(
            self::FROM_A_RECORD + ['record' => $record ?? Uuid::v7()->toRfc4122()],
        );
    }

    /**
     * THE ANSWERS `livestock depredation` CANNOT BE FILED WITHOUT — the species,
     * one count row, a party with a role and a name, and the figure. The four
     * blocks it switched on, each asked its own first question.
     *
     * @return array<string, mixed>
     */
    private static function theBlocksDepredationAsks(): array
    {
        return [
            'blocks' => [
                'species' => ['species' => 'Lion', 'sex' => 'unknown'],
                'counts' => ['rows' => [['quantity' => 'head of stock', 'how_many' => '4']]],
                'parties' => ['rows' => [['role' => 'claimant', 'name' => 'A stock owner']]],
                'money' => ['claimed' => '900000'],
            ],
        ];
    }

    // ── THE FULL PAGE — standalone filing ────────────────────────────────────

    /**
     * A FILING THAT CAME FROM NOWHERE IS A PAGE. It has an address, it survives a
     * reload, and nothing beside it can throw it away — which is the whole reason
     * the centred sheet was retired as the container.
     */
    public function testStandaloneFilingRendersTheFullPageAtItsOwnRoute(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form.ro-form'));
        // Not an overlay: no drawer, no stage, and nothing left of the sheet.
        self::assertCount(0, $crawler->filter('.ro-drawer'));
        self::assertCount(0, $crawler->filter('.ro-stage'));
        self::assertStringNotContainsString('i-sheet', $crawler->html());
        // TWO headed sections down one column: what kind, and what happened.
        // There was a third — "People & evidence — can wait" — and it asked
        // nothing; the promise it carried is one line beside the File control
        // now. A headed step must have a question in it.
        self::assertCount(2, $crawler->filter('form.ro-form .ro-sect'));
        // One chooser per kind of incident, all four, drawn as cards.
        self::assertCount(4, $crawler->filter('.i-catpick .i-catopt'));
        // …and a field set per sub-category, so choosing one swaps the questions
        // without a round trip.
        self::assertCount(16, $crawler->filter('[data-uhifadhi--incident-module--incident-report-target="fieldset"]'));
    }

    /**
     * ONE WAY OUT, AND IT IS BESIDE THE CONTROL IT UNDOES.
     *
     * The page head carries no way back. A filer reads the head before they have
     * written anything and the footer after, and a leave-this-page link at the top
     * of a form somebody is filling in is an exit offered where the decision is not
     * being made. Cancel sits in the file bar, beside File, and says where it goes.
     *
     * AND NEITHER IS THERE A `Configure`. Configure is a TAB-PAGE action — it
     * steps off the data of a surface and into how that surface is set up, so
     * it rides the pages the tab strip is drawn on. A form inside a place is
     * not one of the places, so the shell's frame publishes no Configure here
     * ({@see \Uhifadhi\Bundle\ShellBundle\Frame\Service\ModuleFrameService::configure()}),
     * and the head's action row is empty: Cancel is the one way out.
     */
    public function testThePageHeadCarriesNoBackLinkAndCancelIsTheOneWayOut(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));

        self::assertSame(
            [],
            $crawler->filter('.pghead .pgact a')->each(static fn (Crawler $a): string => trim($a->text())),
        );
        self::assertCount(0, $crawler->filter('.pghead')->reduce(
            fn (Crawler $head): bool => 0 < $head->filter(\sprintf('a[href="%s"]', $this->createUrl($this->uuidOf($area))))->count(),
        ));
        // A walk-in filing came from the register, so Cancel goes back to it.
        self::assertSame(
            $this->createUrl($this->uuidOf($area)),
            $crawler->filter('.ro-filebar a.tgl')->attr('href'),
        );
    }

    /** And a filing that came from a record goes back to the record. */
    public function testCancelReturnsToTheSourceRecordWhenTheFilingCameFromOne(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));

        // The head is bare here too: a filing is a form in a place, not one of
        // the places, so it carries no Configure and no way back.
        self::assertSame(
            [],
            $crawler->filter('.pghead .pgact a')->each(static fn (Crawler $a): string => trim($a->text())),
        );
        self::assertSame(
            '/areas/x/modules/patrols/observation/2',
            $crawler->filter('.ro-filebar a.tgl')->attr('href'),
        );
    }

    // ── FILING FROM A RECORD — the same full page ─────────────────────────────

    /**
     * A FILING THAT ARRIVES FROM A RECORD IS THE SAME FULL PAGE, never a
     * slide-over. The drawer is retired: the filing gets an address it can be
     * reloaded, deep-linked and printed from, and the source card rides at the
     * head of the page rather than inside a panel that a click could dismiss.
     */
    public function testFilingFromARecordRendersTheSameFullPageAndNeverADrawer(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        // The one container: the full page's form, exactly as a standalone filing.
        self::assertCount(1, $crawler->filter('form.ro-form'));
        // Nothing of the retired drawer is left in the document.
        self::assertCount(0, $crawler->filter('.ro-slideover'));
        self::assertCount(0, $crawler->filter('.ro-drawer'));
        self::assertCount(0, $crawler->filter('.ro-behind'));
        self::assertCount(0, $crawler->filter('.ro-dhd'));
        self::assertCount(0, $crawler->filter('.ro-dfoot'));
    }

    /** The source card rides at the head of the full page, above the questions. */
    public function testTheSourceCardRidesAtTheHeadOfTheFullPage(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));

        // At the head of the page, above the form — exactly as design A draws it,
        // and not tucked inside the form or any panel.
        self::assertCount(1, $crawler->filter('.i-src'));
        self::assertCount(0, $crawler->filter('form.ro-form .i-src'));
    }

    /**
     * THE LINE ARRIVES ANSWERED. The observation already said what happened, and
     * asking somebody to write it a second time is the surest way to get a report
     * that never exists. It is editable — and the SOURCE CARD still shows the
     * note verbatim, so trimming the line for the register never touches the
     * original.
     */
    public function testTheLineIsPrefilledFromTheRecordsNoteAndTheCardKeepsTheOriginal(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));

        // A TEXTAREA, in the same grammar as the verbatim note below it: what a
        // person writes at the roadside is a sentence, not a database field.
        $line = $crawler->filter('form textarea[name="title"]');
        self::assertCount(1, $line);
        self::assertSame('Fresh lion tracks 400 m from the bomas.', $line->text());
        // Verbatim, in the rail beside the questions, untouched by anything done
        // to the line.
        self::assertStringContainsString(
            'Fresh lion tracks 400 m from the bomas.',
            $crawler->filter('.i-rail [data-incident-observation] .rr-quote')->text(),
        );
        // …and a prefilled line COUNTS AS FILLED: the gate does not ask for it
        // again, and with the category still to choose the File control is still
        // dead.
        self::assertStringNotContainsString('describe what happened', $crawler->filter('.ro-gate')->text());
        self::assertNotNull($crawler->filter('button.ro-file')->attr('disabled'));
    }

    /**
     * A FILING THAT ARRIVED COMPLETE IS FILEABLE ON SIGHT. Category, line and
     * place all came with the record, so nothing is missing, the gate says
     * nothing, and the control is alive before a single keystroke.
     */
    public function testAFilingThatArrivedCompleteStillWaitsOnTheBlocksItSwitchedOn(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $url = $this->fromARecordUrl($this->uuidOf($area)).'&category=livestock-depredation';
        $crawler = $this->client->request('GET', $url);

        // The three shared answers arrived with the record, so none of them is in
        // the line — and every block the word switched on is, because a block that
        // records nothing is worse than an absent one.
        self::assertNotNull($crawler->filter('button.ro-file')->attr('disabled'));
        self::assertSame(
            'the species · one count row · a party with a role and a name · the loss claimed',
            $crawler->filter('.ro-gate')->text(),
        );
        self::assertNotNull($crawler->filter('.ro-filebar .hint')->attr('hidden'));
    }

    /**
     * A NOTE LONGER THAN THE REGISTER'S LINE IS CLAMPED, NEVER REFUSED. The note
     * is written to be read, not to fit a column; nothing is lost, because the
     * provenance link keeps the original reachable forever.
     */
    public function testALineLongerThanTheRegisterCanPrintIsStoredShortRatherThanRefused(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $html = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)))->html();
        $this->client->request('POST', $this->createUrl($this->uuidOf($area)), [
            '_token' => $this->tokenFrom($html),
            'subcategory' => 'livestock-depredation',
            'title' => str_repeat('a', 260),
            'lat' => '-3.21',
            'lng' => '-29.75',
        ] + self::theBlocksDepredationAsks());

        self::assertResponseRedirects();
        $incident = $this->em->getRepository(Incident::class)->findOneBy([]);
        self::assertNotNull($incident);
        self::assertSame(200, mb_strlen($incident->getTitle()));
    }

    /**
     * A PARTIAL PREFILL STILL RENDERS THE FULL PAGE. A truncated link, a
     * hand-typed URL, a bookmark from a deleted observation — anything that does
     * not carry a whole record — renders the same page, with no source card
     * claiming a provenance it does not have.
     */
    public function testAPartialPrefillRendersTheFullPageWithNoSourceCard(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        // Everything the hand-off sends EXCEPT the record.
        $query = self::FROM_A_RECORD;
        unset($query['label']);

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)).'?'.http_build_query($query));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form.ro-form'));
        self::assertCount(0, $crawler->filter('.ro-drawer'));
        // No record, so nothing claims one.
        self::assertCount(0, $crawler->filter('.i-src'));
    }

    // ── ONE SET OF STEPS, ONE CONTAINER ──────────────────────────────────────

    /**
     * EVERY ENTRY POINT GATES IDENTICALLY. The File control ships DEAD, the same
     * quiet line names the same missing answers, and the same three targets are
     * wired — because it is one page rendered by one set of partials, whether the
     * filing came from a record or from nowhere.
     */
    public function testEveryEntryPointShipsTheSameDeadFileControlAndGate(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        // A record that carried nothing but its identity, so it is asked for
        // exactly the same answers a standalone filing is.
        $bare = $this->reportUrl($this->uuidOf($area)).'?'.http_build_query([
            'source' => 'patrol_observation',
            'record' => Uuid::v7()->toRfc4122(),
            'label' => 'observation 2 of patrol P-0142',
        ]);

        $standalone = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));
        $fromABareRecord = $this->client->request('GET', $bare);

        foreach ([$standalone, $fromABareRecord] as $crawler) {
            self::assertCount(1, $crawler->filter('form.ro-form'));
            $file = $crawler->filter('button.ro-file[type="submit"]');
            self::assertCount(1, $file);
            self::assertNotNull($file->attr('disabled'));
            self::assertCount(1, $crawler->filter('.ro-gate'));
            self::assertSame(
                'choose a category · describe what happened · mark where it happened',
                trim($crawler->filter('.ro-gate')->text()),
            );
        }

        // And what a record DID carry, it is not asked for twice: this one came
        // with a place and a line.
        $prefilled = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));
        self::assertSame('choose a category', trim($prefilled->filter('.ro-gate')->text()));
    }

    /**
     * ONE SET OF STEP PARTIALS, ONE CONTAINER. Whatever the entry point, the page
     * renders every sub-category's field set and every category chooser, wired to
     * the same targets, and drawn as the design's cards.
     */
    public function testTheFullPageRendersEveryStepWiredToTheController(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $standalone = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));
        $fromARecord = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));

        foreach ([$standalone, $fromARecord] as $crawler) {
            $identifier = $crawler->filter('[data-controller*="incident-report"]')->attr('data-controller');
            self::assertNotNull($identifier);

            self::assertCount(4, $crawler->filter(\sprintf('[data-%s-target="category"][data-subcategories*=","]', $identifier)));
            self::assertCount(16, $crawler->filter(\sprintf('[data-%s-target="fieldset"]', $identifier)));
            self::assertCount(1, $crawler->filter(\sprintf('[data-%s-target="gate"]', $identifier)));
            self::assertCount(1, $crawler->filter(\sprintf('[data-%s-target="file"]', $identifier)));
            self::assertCount(1, $crawler->filter(\sprintf('[data-%s-target="hint"]', $identifier)));
            // One line, one place, one form — the fields are hoisted out of the
            // swapped field sets, so there is exactly one of each to answer.
            self::assertCount(1, $crawler->filter('form textarea[name="title"]'));
            self::assertCount(1, $crawler->filter('form input[name="lat"]'));
            // The kind is drawn as the design's cards, and there are no drawer
            // chips anywhere.
            self::assertCount(4, $crawler->filter('.i-catpick .i-catopt'));
            self::assertCount(0, $crawler->filter('.ro-chips'));
        }
    }

    /**
     * THE QUICK-FILE DISCIPLINE IS ONE SENTENCE, NOT A SECTION.
     *
     * Section 3 was "People & evidence — can wait": a headed section containing
     * four rows of prose, each with a "can wait" pill, and an explainer listing
     * how bad / people / evidence / money. It had NO INPUTS. A form section with
     * nothing to fill in is teaching copy dressed as UI — the reader counts three
     * steps, finds the third asks nothing, and learns that a heading here does
     * not mean a question.
     *
     * The promise survives, because the promise was the point: one quiet line
     * beside the File control, saying what is needed now and what is added on the
     * record afterwards. Two steps, two questions, and the discipline stated once
     * where the decision to file is actually made.
     */
    public function testTheQuickFileDisciplineIsOneLineAndNotASection(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $standalone = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));
        $fromARecord = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));

        foreach ([$standalone, $fromARecord] as $crawler) {
            // The section, its rows and its pills are gone from the document.
            self::assertCount(0, $crawler->filter('.ro-later'));
            self::assertStringNotContainsString('Then, on the record', $crawler->text());
            self::assertStringNotContainsString('People & evidence', $crawler->text());

            // …and the one line stands in their place, beside the File control.
            $promise = $crawler->filter('.ro-promise');
            self::assertCount(1, $promise);
            self::assertStringContainsString(
                'Only the kind, what happened, where, and each block\'s own first answer are needed now. The paperwork — contacts, ID numbers, custody references, dates and facilities — is added on the record afterwards.',
                $promise->text(),
            );

            // Exactly the three ruled requirements are still marked needed: the
            // kind, the line, and the place.
            self::assertCount(3, $crawler->filter('.ro-req'));
        }
    }

    /**
     * THE RULES CARD IS GONE — removed, not moved to the rail.
     *
     * It restated, as nine mono rows at the foot of the page, rules the form was
     * already enforcing in front of the reader: which answers are marked, which
     * blocks are drawn, that the File control is dead until the gate is answered.
     * A card that repeats the page it sits under is a second place for the same
     * truth to be written, and the two drift. The one thing on it that was NOT a
     * restatement — what filing costs — is the entry card's own line, and that
     * stays.
     */
    public function testTheRulesCardIsGoneFromTheReportPage(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $page = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));

        self::assertCount(0, $page->filter('.i-rules'));
        self::assertStringNotContainsString('The rules the form enforces', $page->text());
        self::assertStringNotContainsString('the container may change; these do not', $page->html());
        // …and nothing of it reappeared in the rail.
        self::assertStringNotContainsString('reported — never verified', $page->filter('.i-rail')->text());
    }

    /**
     * THE MONEY ROW IS ABSENT, NOT DISABLED, on a category that carries none.
     * The field sets are in the document, so this is a claim about which of them
     * has a money row at all.
     */
    public function testOnlyCategoriesThatCarryMoneyHaveAMoneyRow(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));

        $depredation = $crawler->filter('[data-uhifadhi--incident-module--incident-report-target="fieldset"][data-subcategory="livestock-depredation"]')->html();
        self::assertStringContainsString('Loss claimed', $depredation);

        $natural = $crawler->filter('[data-uhifadhi--incident-module--incident-report-target="fieldset"][data-subcategory="natural-mortality"]')->html();
        self::assertStringNotContainsString('Loss claimed', $natural);
        self::assertStringNotContainsString('Fine assessed', $natural);

        // Roadkill sits beside natural mortality under the same kind and DOES
        // carry money — which is why the money row is a sub-category's business
        // and never a category's.
        $roadkill = $crawler->filter('[data-uhifadhi--incident-module--incident-report-target="fieldset"][data-subcategory="roadkill"]')->html();
        self::assertStringContainsString('Fine assessed', $roadkill);
    }

    /**
     * STEP 2 IS A RUN OF FOLDS, ONE PER BLOCK THE WORD SWITCHED ON — the first
     * open and the rest shut, in the kinds editor's order, each summary line
     * naming the block, saying what it is for, printing its question count and
     * carrying its mark.
     */
    public function testStepTwoDrawsOneFoldPerBlockTheFirstOpenAndTheRestShut(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));
        $folds = $crawler->filter('[data-subcategory="livestock-depredation"] .i-blocks > .fold');

        self::assertCount(4, $folds);
        self::assertSame(
            ['Species', 'Counts', 'Parties', 'Money · compensation'],
            $folds->each(static fn (Crawler $fold): string => $fold->filter('.sum > b')->text()),
        );
        self::assertSame(
            ['3 questions', '2 questions', '5 questions', '1 question'],
            $folds->each(static fn (Crawler $fold): string => $fold->filter('.sum > .n')->text()),
        );
        // The first is open; every other one is shut, which is the ruled
        // arrangement and the reason a summary line has to carry the mark.
        self::assertSame(
            [false, true, true, true],
            $folds->each(static fn (Crawler $fold): bool => str_contains((string) $fold->attr('class'), 'shut')),
        );
        self::assertCount(4, $folds->filter('.sum > .fwhen.req'));
        // The money fold wears the money token, and it is the only one that does.
        self::assertSame(
            [false, false, false, true],
            $folds->each(static fn (Crawler $fold): bool => str_contains((string) $fold->attr('class'), 'money')),
        );
    }

    /**
     * A REPEATING BLOCK SHIPS ONE ROW, THE CONTROL TO GROW IT AND THE MARK THAT
     * SAYS A ROW IS WANTED — and the row's cells post as a row, so two of them
     * are two answers rather than one overwritten.
     */
    public function testARepeatingBlockShipsOneRowAndTheControlToGrowIt(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));
        $counts = $crawler->filter('[data-subcategory="livestock-depredation"] .i-blocks > .fold')->eq(1);

        self::assertCount(1, $counts->filter('.reps > .rep'));
        self::assertSame('+ Add a quantity', $counts->filter('.repfoot .repadd')->text());
        self::assertSame('one row needed to file', $counts->filter('.repfoot .fwhen.req')->text());
        self::assertSame(
            ['blocks[counts][rows][0][quantity]', 'blocks[counts][rows][0][how_many]'],
            $counts->filter('.rep [name]')->each(static fn (Crawler $cell): string => (string) $cell->attr('name')),
        );
        // Both cells of a count row are what the row cannot be without.
        self::assertCount(2, $counts->filter('.rep [data-need-row]'));
    }

    /**
     * STEP 2 OFFERS THE SIBLINGS. Choosing a kind in step 1 picks its first
     * sub-category; the chips inside step 2 move between the others, and each one
     * swaps the whole field set — because the sub-category is what decides the
     * questions.
     */
    public function testStepTwoOffersTheSiblingsUnderTheChosenKind(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));

        $mortality = $crawler->filter('[data-uhifadhi--incident-module--incident-report-target="fieldset"][data-subcategory="roadkill"]');
        $siblings = $mortality->filter('.i-taxsub a')->each(static fn ($node) => $node->attr('data-subcategory'));

        self::assertSame(['roadkill', 'natural-mortality', 'disease-die-off', 'poisoning'], $siblings);
    }

    // ── THE SERVER, UNCHANGED ────────────────────────────────────────────────

    /** Filing writes an incident, at `reported`, and lands on its case file. */
    public function testFilingCreatesAReportedIncidentAndOpensIt(): void
    {
        $area = $this->anAreaWithKinds();
        $this->aZone($area, 'North Gate');
        $reporter = $this->aReporter();
        $this->client->loginUser($reporter);

        $html = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)))->html();
        $this->client->request('POST', $this->createUrl($this->uuidOf($area)), [
            '_token' => $this->tokenFrom($html),
            'subcategory' => 'livestock-depredation',
            'title' => 'Lion killed four goats at Riverside',
            'lat' => '-3.21',
            'lng' => '-29.75',
            'severity' => 'high',
            'narrative' => 'They came in the night.',
        ] + self::theBlocksDepredationAsks());

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $incident = $this->em->getRepository(Incident::class)->findOneBy(['title' => 'Lion killed four goats at Riverside']);
        self::assertNotNull($incident);
        self::assertSame(IncidentStatusEnum::Reported, $incident->getStatus());
        self::assertSame('high', $incident->getSeverity()->value);
        self::assertSame('They came in the night.', $incident->getNarrative());
        // THE ANSWERS ARE KEPT PER BLOCK, and the repeating ones as rows.
        self::assertSame([
            'species' => ['species' => 'Lion', 'sex' => 'unknown'],
            'counts' => ['rows' => [['quantity' => 'head of stock', 'how_many' => '4']]],
            'parties' => ['rows' => [['role' => 'claimant', 'name' => 'A stock owner']]],
        ], $incident->getBlockAnswers());
        // THE FIGURE IS THE CLAIMED ONE AND NOT A MONEY RECORD: nobody has judged
        // anything at filing, and the money flow opens the record where it says.
        self::assertSame(900_000, $incident->getClaimedAtFiling());
        self::assertNull($incident->getMoney());
        // The point was resolved to a zone in PostGIS, once, at filing.
        self::assertSame('North Gate', $incident->zoneLabel());
        self::assertSame($reporter->getId(), $incident->getReportedBy()?->getId());
    }

    /**
     * ALMOST NOTHING IS REQUIRED — but the two facts an incident cannot be
     * without are: what kind of thing happened, and where.
     */
    public function testAReportWithNoCategoryOrPlaceIsRefusedWithItsFormBack(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $html = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)))->html();
        $crawler = $this->client->request('POST', $this->createUrl($this->uuidOf($area)), [
            '_token' => $this->tokenFrom($html),
            'subcategory' => '',
            'title' => '',
        ]);

        // 422: understood perfectly, and simply cannot be stored.
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choose what kind of incident this was', $crawler->filter('.i-errors')->text());
        self::assertStringContainsString('pick the place on the map', $crawler->filter('.i-errors')->text());
        self::assertSame(0, $this->em->getRepository(Incident::class)->count([]));
    }

    /**
     * AND THE SERVER AGREES. The gate is not a decoration in front of a
     * permissive endpoint: a filing missing one of the required steps is refused
     * with the same reason the quiet line gave.
     */
    public function testAReportWithAPlaceButNothingSaidIsRefused(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $html = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)))->html();
        $crawler = $this->client->request('POST', $this->createUrl($this->uuidOf($area)), [
            '_token' => $this->tokenFrom($html),
            'subcategory' => 'livestock-depredation',
            'title' => '   ',
            'lat' => '-3.21',
            'lng' => '-29.75',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('One line saying what happened', $crawler->filter('.i-errors')->text());
        self::assertSame(0, $this->em->getRepository(Incident::class)->count([]));
    }

    /**
     * A REFUSED FILING FROM A RECORD COMES BACK ON THE SAME FULL PAGE, with the
     * source card still at its head and everything that was typed still there.
     * Being refused is not a new entry point, and there is one container either
     * way — so it is answered where it was made.
     */
    public function testARefusedFilingFromARecordComesBackOnTheFullPage(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $query = http_build_query(self::FROM_A_RECORD + ['record' => Uuid::v7()->toRfc4122()]);
        $html = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)).'?'.$query)->html();

        $crawler = $this->client->request('POST', $this->createUrl($this->uuidOf($area)).'?'.$query, [
            '_token' => $this->tokenFrom($html),
            'subcategory' => 'livestock-depredation',
            'title' => '',
            'lat' => '-3.2014',
            'lng' => '-29.5378',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('form.ro-form'));
        self::assertCount(0, $crawler->filter('.ro-drawer'));
        // The source card is still there — the provenance survived the refusal.
        self::assertCount(1, $crawler->filter('.i-src'));
        self::assertStringContainsString('One line saying what happened', $crawler->filter('.i-errors')->text());
    }

    /**
     * THE SOURCE RECORD, IN THE RAIL, IN THE WORDS AND FACTS OF THE RECORD.
     *
     * "How is the filing user going to remember the context of what happened?" By
     * reading it — the observation's own sentence, quoted, and the record itself:
     * which patrol, whose eyes, when, where, and what kind of record it was.
     * Nothing on it is editable: provenance is written once, at filing.
     */
    public function testTheRailShowsTheSourceObservationInFull(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $query = http_build_query([
            'source' => 'patrol_observation',
            'record' => Uuid::v7()->toRfc4122(),
            'label' => 'OBS-02 · lion tracks',
            'back' => '/areas/x/modules/patrols/observation/2',
            // In the source's own zone — East Africa Time, where the observation
            // was made.
            'at' => '2026-08-22T08:15:00+03:00',
            'lat' => '-3.2014',
            'lng' => '-29.5378',
            'note' => 'Fresh lion tracks 400 m from the bomas.',
            'patrol' => 'P-0142 · foot patrol',
            'ranger' => 'S. Laizer · ranger',
        ]);

        $card = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)).'?'.$query)
            ->filter('.i-rail [data-incident-observation]');

        self::assertCount(1, $card);
        // THE TAB NAMES THE RECORD AND NOTHING ELSE: a 340px rail clips a longer
        // one, and "not editable" is about the rows, so it rides on their label.
        self::assertSame('The observation· OBS-02 · lion tracks', trim($card->filter('.tab')->text()));
        self::assertSame('· OBS-02 · lion tracks', trim($card->filter('.tab .src')->text()));
        self::assertSame(
            'The record itself · not editable',
            trim($card->filter('.rr-sub')->first()->text()),
        );
        // The observation's own words, quoted and not editable.
        self::assertStringContainsString('Fresh lion tracks 400 m from the bomas.', $card->filter('.rr-quote')->text());
        self::assertCount(0, $card->filter('textarea, input, select'));
        // NO PLATE. A 176px map beside a form answers "where" with a picture a
        // reader has to interpret, while the Where row below answers it in the
        // notation the observation page itself uses — and the plate cost the page
        // the atlas's whole map machinery to say the same thing less exactly.
        self::assertCount(0, $card->filter('.map-plate'));
        self::assertStringNotContainsString('--map-plate-height', $card->html());
        // The record itself: patrol, ranger, when, where.
        $rows = $card->filter('.rln')->each(static fn (Crawler $row): string => $row->text());
        self::assertStringContainsString('P-0142 · foot patrol', implode(' | ', $rows));
        self::assertStringContainsString('S. Laizer · ranger', implode(' | ', $rows));
        // THE MOMENT AS A MACHINE INSTANT: the row's text is the register's own
        // zone — 05:15 for an 08:15+03:00 observation — and the `<time>` around it
        // carries the instant, so the frame reads it in the zone of whoever is
        // looking. A wall clock printed in a zone the reader is not in is the
        // defect; printing the OBSERVER's zone beside a form that prints another
        // is two of them.
        self::assertStringContainsString('05:15', implode(' | ', $rows));
        self::assertSame(
            '2026-08-22T05:15:00+00:00',
            $card->filter('.rln time')->attr('datetime'),
        );
        // The position, in the observation page's own notation.
        self::assertStringContainsString('3°12\'05"S 29°32\'16"W', implode(' | ', $rows));
        // AND THE SOURCE ROW IS THE WAY BACK TO WHAT THE CARD LEAVES OUT: the
        // record's own map, its photographs, its history, all current on the page
        // that owns them.
        $source = $card->filter('.rln')->last();
        self::assertStringContainsString('Source', $source->text());
        self::assertSame('/areas/x/modules/patrols/observation/2', $source->filter('a')->attr('href'));
        self::assertStringContainsString('its position and its photographs are on it', $source->text());
    }

    /**
     * AND THE STRIP AT THE TOP SHRINKS TO ITS ONE PROVENANCE LINE. Its job was
     * never to be read — it was to prove the observation exists and link back to
     * it. Once the rail carries the record legibly, a second copy of the words,
     * the position and the thumbnails above the form is duplication on the one
     * page a filer is trying to get through.
     */
    public function testTheProvenanceStripShrinksToOneLineWhenTheRailCarriesTheRecord(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $strip = $this->client->request('GET', $this->fromAStubbedRecordUrl($this->uuidOf($area)))->filter('.i-src');

        self::assertCount(1, $strip);
        // The provenance line, and a way back to the record.
        self::assertStringContainsString('Filed from observation 2 of patrol P-0142', $strip->filter('.hd b')->text());
        self::assertSame('/areas/x/modules/patrols/observation/2', $strip->filter('.hd a.go')->attr('href'));
        self::assertStringContainsString('are in the rail', $strip->filter('.i-srcnote')->text());
        // …and nothing the rail now carries.
        self::assertCount(0, $strip->filter('.note'));
        self::assertCount(0, $strip->filter('.facts'));
        self::assertCount(0, $strip->filter('.shots'));
    }

    // ── THE RAIL — WHAT THIS KIND ASKS ───────────────────────────────────────

    /**
     * A WALK-IN FILING HAS NO OBSERVATION, AND THE RAIL IS STILL WORTH HAVING.
     *
     * Somebody who walked into an office is the commonest filing there is, and
     * there is no record behind it to show. The rail then carries the checklist
     * alone — and it has to look like the whole of what the rail is for, not like
     * a card with a hole above it.
     */
    public function testAWalkInFilingShowsTheChecklistAloneAndNoObservationCard(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $rail = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)))->filter('.i-rail');

        self::assertCount(1, $rail);
        self::assertCount(0, $rail->filter('[data-incident-observation]'));
        // One card per sub-category, the way the form carries one field set per
        // sub-category — and none of them showing, because nothing is chosen.
        self::assertCount(16, $rail->filter('[data-incident-asks]'));
        self::assertCount(0, $rail->filter('[data-incident-asks]:not([hidden])'));
        // …so the rail says what choosing will do rather than standing empty.
        self::assertCount(1, $rail->filter('.rr-none[data-incident-asks-empty]'));
    }

    /**
     * WHAT THIS KIND ASKS. The form folds every block but the first, which is the
     * right answer to length and the wrong answer to surprise — a filer cannot see
     * what is coming. So the rail names every block the chosen sub-category
     * switched on, each one's defining question, and how much is inside it.
     *
     * It INFORMS AND NEVER GATES: the marks are the gate's own, read from the
     * gate's state in the browser, which is why there is exactly one of them on
     * the page and the rail can never disagree with the File control.
     */
    public function testTheChecklistNamesTheBlocksThePreselectedSubCategorySwitchedOn(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $url = $this->fromARecordUrl($this->uuidOf($area)).'&category=livestock-depredation';
        $asks = $this->client->request('GET', $url)
            ->filter('.i-rail [data-incident-asks="livestock-depredation"]');

        self::assertCount(1, $asks);
        self::assertNull($asks->attr('hidden'));
        // THE TAB CARRIES THE TITLE ALONE. A 340px rail clips a tab that also
        // prices the word, so the price moves to the scope line under the progress
        // bar, where it is read with the list it describes.
        self::assertSame('What livestock depredation asks', trim($asks->filter('.tab')->text()));
        self::assertCount(0, $asks->filter('.tab .src'));
        self::assertSame(
            '4 blocks · 11 questions — the sub-category switches these on, and this list changes with it',
            trim($asks->filter('.rr-scope')->text()),
        );

        // SEVEN ROWS: the three answers every incident owes, then the four blocks
        // this word switched on.
        $rows = $asks->filter('.rr-q');
        self::assertCount(7, $rows);
        self::assertSame(
            ['Category', 'What happened', 'Where it happened', 'Species', 'Counts', 'Parties', 'Money · compensation'],
            $rows->each(static fn (Crawler $row): string => $row->children('div')->children('b')->text()),
        );
        // Each block row carries the block's own caption AND the DEFINING answer,
        // named the way the gate misses it — the same string, so a reworded gate
        // cannot leave the rail describing a different form.
        self::assertSame(
            [
                'which animal, and what is known about it. It cannot do without the species.',
                'how many of what. It cannot do without one count row.',
                'the people on the record, each in a role. It cannot do without a party with a role and a name.',
                'the authority pays the claimant. It cannot do without the loss claimed.',
            ],
            $rows->slice(3)->each(static fn (Crawler $row): string => $row->filter('em')->text()),
        );
        // …and how much is behind it, and whether the form has it open.
        self::assertSame(
            ['step 1', 'step 2', 'step 2', '3 questions', '2 questions', '5 questions', '1 question'],
            $rows->each(static fn (Crawler $row): string => explode(' · ', $row->filter('.n')->text())[0]),
        );
        self::assertSame(
            ['open', 'folded', 'folded', 'folded'],
            $rows->slice(3)->each(static fn (Crawler $row): string => explode(' · ', $row->filter('.n')->text())[1]),
        );
        // THE MARK IS THE GATE'S. Every row names the missing answer the gate
        // names, so the browser can mark it from one source of truth.
        self::assertSame(
            [
                'choose a category',
                'describe what happened',
                'mark where it happened',
                'the species',
                'one count row',
                'a party with a role and a name',
                'the loss claimed',
            ],
            $rows->each(static fn (Crawler $row): ?string => $row->attr('data-incident-report-need')),
        );

        // THE OTHER BLOCKS ARE ABSENT, NOT GREYED, and the rail says so in words.
        $absent = $asks->filter('.rr-none')->text();
        self::assertStringContainsString('The other eight blocks', $absent);
        self::assertStringContainsString('method & means', $absent);
        self::assertStringContainsString('absent from this form, not greyed out', $absent);
    }

    /**
     * AND THE PROGRESS LINE MIRRORS THE GATE'S COUNT, not a count of its own. The
     * three answers every incident owes are rows too, because the gate holds the
     * filing on them exactly as it holds it on a block.
     */
    public function testTheChecklistProgressLineCountsWhatTheGateIsStillMissing(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $url = $this->fromARecordUrl($this->uuidOf($area)).'&category=livestock-depredation';
        $crawler = $this->client->request('GET', $url);

        // The category, the line and the place all came with the record, so four
        // of the seven rows are still needed — and those four are exactly the four
        // the footer's gate line names.
        $shown = $crawler->filter('[data-incident-asks="livestock-depredation"]');
        self::assertSame('4 of 7 still needed', $shown->filter('.rr-prog b')->text());
        self::assertSame(
            'the species · one count row · a party with a role and a name · the loss claimed',
            $crawler->filter('.ro-gate')->text(),
        );
        // Three answered, four needed — marked the way the state rail marks a step
        // it has passed, and NUMBERED in the order they are still owed.
        self::assertCount(3, $shown->filter('.rr-q.done'));
        self::assertSame(
            ['1', '2', '3', '4'],
            $shown->filter('.rr-q.need')->each(static fn (Crawler $row): string => $row->children('i')->text()),
        );
        // The bar shows the share that is done, never a count of its own.
        self::assertStringContainsString('width:43%', (string) $shown->filter('.rr-prog .bar i')->attr('style'));
    }

    /**
     * THE RAIL IS BESIDE THE FORM, IN ONE SCROLLER. A second scroll region beside
     * a form is a second place to lose your position in; the two columns are one
     * page, and below 1160px the rail stacks under the form rather than squeezing
     * it.
     */
    public function testTheRailSitsBesideTheFormInsideTheOneStimulusController(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));

        // One wrapper, carrying the form column and the rail as its two children.
        $wrap = $crawler->filter('.i-rwrap');
        self::assertCount(1, $wrap);
        self::assertCount(1, $wrap->children('.ro-col')->children('form.ro-form'));
        self::assertCount(1, $wrap->children('.i-rail'));
        // The gate drives both, so both are inside the one controller.
        self::assertCount(1, $crawler->filter('[data-controller*="incident-report"] .i-rail'));
    }

    /**
     * THE RAIL DRAWS NO PHOTOGRAPHS, EVEN WHERE THE RECORD HAS THEM.
     *
     * The filer is writing a report, not reviewing a gallery, and the pictures
     * belong to the record that holds them and to the case file this filing
     * becomes. So the rail asks the platform's file registry nothing, opens no
     * preview overlay, and does not pay for the overlay's stylesheet on a page
     * with nothing to open in it.
     */
    public function testTheRailDrawsNoPhotographsOfTheSourceRecord(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        // The record the fixture module really does hold photographs for.
        $crawler = $this->client->request('GET', $this->fromAStubbedRecordUrl($this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-incident-observation]');
        self::assertCount(1, $card);
        self::assertCount(0, $card->filter('.rr-shots'));
        self::assertCount(0, $card->filter('img'));
        self::assertCount(0, $card->filter('button'));
        // Nothing to open, so neither the shared overlay nor its sheet is asked for.
        self::assertCount(0, $crawler->filter('[data-controller*="preview"]'));
        self::assertStringNotContainsString('uhifadhistorage/preview', $crawler->html());
    }

    /** Nothing came from anywhere, so there is no card claiming otherwise. */
    public function testThereIsNoSourceCardOnAFilingThatCameFromNowhere(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));

        self::assertSelectorNotExists('.i-src');
    }

    /**
     * ARRIVING FROM A PATROL OBSERVATION. The hand-off is a query string, because the
     * two modules are separate bundles and neither may name the other's classes.
     * Everything it carries is a guess the filer may overrule — except the link,
     * which is written once and never again.
     */
    public function testAReportArrivingFromAnObservationCarriesItsProvenance(): void
    {
        $area = $this->anAreaWithKinds();
        $observation = Uuid::v7();
        $this->client->loginUser($this->aReporter());

        $query = http_build_query([
            'source' => 'patrol_observation',
            'record' => $observation->toRfc4122(),
            'label' => 'observation 2 of patrol P-0142',
            'back' => '/areas/x/modules/patrols/observation/2',
            'at' => '2026-08-22T08:15:00+00:00',
            'lat' => '-3.2014',
            'lng' => '-29.5378',
            'category' => 'livestock-depredation',
            'note' => 'Fresh lion tracks 400 m from the bomas.',
        ]);

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)).'?'.$query);

        self::assertResponseIsSuccessful();
        // The page says where it came from, and the note travelled with it.
        self::assertStringContainsString('observation 2 of patrol P-0142', $crawler->filter('.i-src')->text());
        self::assertStringContainsString('Fresh lion tracks', $crawler->html());

        $this->client->request('POST', $this->createUrl($this->uuidOf($area)).'?'.$query, [
            '_token' => $this->tokenFrom($crawler->html()),
            'subcategory' => 'livestock-depredation',
            'title' => 'Fresh lion tracks 400 m from North Gate bomas',
            'lat' => '-3.2014',
            'lng' => '-29.5378',
        ] + self::theBlocksDepredationAsks());

        self::assertResponseRedirects();
        $incident = $this->em->getRepository(Incident::class)->findOneBy(['title' => 'Fresh lion tracks 400 m from North Gate bomas']);
        self::assertNotNull($incident);
        self::assertTrue($incident->hasProvenance());
        self::assertSame($observation->toRfc4122(), $incident->getSourceRecordUuid()?->toRfc4122());
        self::assertSame('observation 2 of patrol P-0142', $incident->getSourceRecordLabel());
        self::assertSame(IncidentSourceEnum::PatrolObservation, $incident->getSource());
    }

    /** A bad link opens an EMPTY form — as a page, since nothing came with it. */
    public function testAnUnreadablePrefillJustOpensAnEmptyForm(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)).'?record=not-a-uuid&lat=999&at=nonsense');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.i-src'));
        self::assertCount(1, $crawler->filter('form.ro-form'));
    }

    /** Filing needs "incidents.record". Signed in is not the same as permitted. */
    public function testFilingIsRefusedWithoutTheRecordPermission(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aUser('bystander@example.test', 'No', 'Rights'));

        $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));

        self::assertResponseStatusCodeSame(403);
    }

    /** And a write with no token is refused, whoever is signed in. */
    public function testAWriteWithoutACsrfTokenIsRefused(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $this->client->request('POST', $this->createUrl($this->uuidOf($area)), [
            'subcategory' => 'livestock-depredation',
            'title' => 'Lion killed four goats at Riverside',
            'lat' => '-3.21',
            'lng' => '-29.75',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em->getRepository(Incident::class)->count([]));
    }

    /**
     * THE DASHBOARD'S REPORT BUTTON IS NOT ANCHORED TO A RECORD, so it is
     * STANDALONE filing and it navigates to the full page. Nothing is mounted
     * over the dashboard any more — the centred sheet is retired as a container.
     */
    public function testTheDashboardReportButtonNavigatesToTheFullPage(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $dashboard = $this->client->request('GET', $this->createUrl($this->uuidOf($area)));

        self::assertSame($this->reportUrl($this->uuidOf($area)), $dashboard->filter('.pgact a.cta')->attr('href'));
        // A plain link, not a trigger: nothing opens over this page.
        self::assertNull($dashboard->filter('.pgact a.cta')->attr('data-action'));
        self::assertStringNotContainsString('i-sheet', $dashboard->html());
        self::assertCount(0, $dashboard->filter('.ro-drawer'));
        self::assertCount(0, $dashboard->filter('form.ro-form'));
    }

    // ── A POINT OUTSIDE THE AREA'S BOUNDARY ─────────────────────────────────

    /**
     * WARN, THE DEFAULT: the person filing is told before anything is filed,
     * and may move the point or file it as it is.
     */
    public function testAPointOutsideTheBoundaryIsToldBeforeItIsFiled(): void
    {
        $area = $this->anAreaWithKinds('Kilimani');
        $crawler = $this->fileOutside($area);

        self::assertResponseStatusCodeSame(422);
        $banner = $crawler->filter('form .banner');
        self::assertCount(1, $banner);
        self::assertSame('This point is outside Kilimani, 1.2 km beyond its boundary. — Move it inside, or file it as outside the area.', trim($banner->filter('.banner-t')->text()));
        self::assertSame(['Move the point', 'File it outside the area'], $banner->filter('.banner-acts .btn')->each(static fn (Crawler $b): string => trim($b->text())));
        self::assertSame('outside', $banner->filter('button[name="placement"]')->attr('value'));
        self::assertSame(0, $this->em->getRepository(Incident::class)->count([]));
    }

    public function testFilingItOutsideTheAreaKeepsThePointAndMarksTheIncident(): void
    {
        $area = $this->anAreaWithKinds('Kilimani');
        $this->fileOutside($area, ['placement' => 'outside']);

        self::assertResponseRedirects();
        $incident = $this->em->getRepository(Incident::class)->findOneBy([]);
        self::assertNotNull($incident);
        self::assertTrue($incident->isOutsideArea());
    }

    /** REFUSE: only moving the point is offered, and the gate names the point as still needed. */
    public function testAnAreaThatRefusesOffersOnlyMovingThePoint(): void
    {
        $area = $this->anAreaWithKinds('Kilimani');
        $this->setForTheArea($area, PlacementSettings::OUTSIDE_BOUNDARY, 'refuse');
        $crawler = $this->fileOutside($area, ['placement' => 'outside']);

        self::assertResponseStatusCodeSame(422);
        $banner = $crawler->filter('form .banner');
        self::assertSame('This point is outside Kilimani, 1.2 km beyond its boundary. — This area files reports inside its boundary only.', trim($banner->filter('.banner-t')->text()));
        self::assertSame(['Move the point'], $banner->filter('.banner-acts .btn')->each(static fn (Crawler $b): string => trim($b->text())));
        self::assertCount(1, $crawler->filter('form input[type="hidden"][data-need="a point inside Kilimani"]'));
        self::assertSame(0, $this->em->getRepository(Incident::class)->count([]));
    }

    public function testAnAreaThatAcceptsFilesItMarkedOutside(): void
    {
        $area = $this->anAreaWithKinds('Kilimani');
        $this->setForTheArea($area, PlacementSettings::OUTSIDE_BOUNDARY, 'accept');
        $this->fileOutside($area);

        self::assertResponseRedirects();
        self::assertTrue($this->em->getRepository(Incident::class)->findOneBy([])?->isOutsideArea());
    }

    public function testAPointInsideTheBoundaryShowsNoBannerAndIsNotMarked(): void
    {
        $area = $this->anAreaWithKinds('Kilimani');
        $this->fileOutside($area, ['lng' => '-29.75']);

        self::assertResponseRedirects();
        self::assertFalse($this->em->getRepository(Incident::class)->findOneBy([])?->isOutsideArea());
    }

    /**
     * Files a report whose point is 0.011° east of the area's boundary at
     * 3.21° S, about 1.2 km beyond it.
     *
     * @param array<string, string> $overrides
     */
    private function fileOutside(AreaOfInterest $area, array $overrides = []): Crawler
    {
        $this->client->loginUser($this->aReporter());
        $html = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)))->html();

        return $this->client->request('POST', $this->createUrl($this->uuidOf($area)), $overrides + [
            '_token' => $this->tokenFrom($html),
            'subcategory' => 'livestock-depredation',
            'title' => 'Lion killed four goats beyond the river',
            'lat' => '-3.21',
            'lng' => '-28.989',
        ] + self::theBlocksDepredationAsks());
    }

    private function setForTheArea(AreaOfInterest $area, string $key, string $value): void
    {
        /** @var SettingsWriter $writer */
        $writer = static::getContainer()->get('test_public.registry.settings.writer');
        $writer->apply([new SettingChange($key, SettingDepth::Area, $this->uuidOf($area), $value)], 'test');
    }
}
