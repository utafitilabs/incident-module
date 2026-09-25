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
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;
use Uhifadhi\Incident\Devkit\DemoMonth;
use Uhifadhi\Incident\Entity\TaxonomyKind;
use Uhifadhi\Incident\Entity\TaxonomySubcategory;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Service\TaxonomyAdminService;

/**
 * THE AREA-SCOPED TAXONOMY ADMIN, over HTTP. One route, two data conditions —
 * the empty first-run start and the populated two-pane manager — plus the ruled
 * invariants: area scope, deactivate-never-delete, per-area uniqueness, and the
 * `incident-vocabulary.configure` gate on every write.
 */
final class TaxonomyAdminPageTest extends FunctionalTestCase
{
    private function kindsUrl(AreaOfInterest $area): string
    {
        return \sprintf('/areas/%s/modules/incidents/kinds', $this->uuidOf($area));
    }

    private function admin(): TaxonomyAdminService
    {
        /** @var TaxonomyAdminService $admin */
        $admin = static::getContainer()->get('test_public.incident.taxonomy_admin');

        return $admin;
    }

    private function kindCount(AreaOfInterest $area): int
    {
        return \count($this->em->getRepository(TaxonomyKind::class)->findBy(['area' => $area]));
    }

    // ── what the demo seeds is what the editor shows ─────────────────────────

    /**
     * THE LOOP THE RULING CLOSES. Whatever `fixtures:demo` seeds must appear in
     * this editor — a demo that filed incidents against words an administrator
     * could not see, rename or retire would be demonstrating a product that does
     * not exist.
     *
     * So this runs the declaration devkit collects, against an area with nothing
     * in it, and then loads the page a person loads.
     */
    public function testTheKindsTheDemoSeedsAreTheKindsTheEditorShows(): void
    {
        $area = $this->anArea('Southern Reserve');
        $this->demoContent()->load();
        $this->em->clear();

        $this->client->loginUser($this->aManager());
        $crawler = $this->client->request('GET', $this->kindsUrl($area));

        self::assertResponseIsSuccessful();
        // The populated manager, not the empty start.
        self::assertCount(1, $crawler->filter('.tx-mgr'));

        $labels = $crawler->filter('.tx-kind .nm')->each(static fn ($node): string => trim($node->text()));
        foreach (DemoMonth::kinds() as $code => $definition) {
            self::assertContains(
                $definition['label'].$code,
                $labels,
                \sprintf('The demo seeded "%s" and the editor has to show it, under its own wire-code.', $definition['label']),
            );
        }

        // And the words under the selected kind carry what the demo gave them —
        // the term and the fields, not only the name.
        $subs = $crawler->filter('.tx-sub')->count();
        self::assertGreaterThan(0, $subs);
        self::assertGreaterThan(0, $crawler->filter('.tx-sub .blocks .tx-blk')->count());
    }

    /** Devkit's inert declaration, played by the suite the way devkit plays it. */
    private function demoContent(): ContentProviderInterface
    {
        /** @var ContentProviderInterface $provider */
        $provider = static::getContainer()->get('test_public.incident.devkit.content');

        return $provider;
    }

    // ── the empty start ──────────────────────────────────────────────────────

    public function testTheEmptyStartRendersTheGhostAndTheWriteFirstKindPath(): void
    {
        $area = $this->anArea('Southern Reserve');
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->kindsUrl($area));

        self::assertResponseIsSuccessful();
        // The empty condition, not the manager.
        self::assertCount(1, $crawler->filter('.tx-empty'));
        self::assertCount(0, $crawler->filter('.tx-mgr'));
        // The inert ghost sketch of a taxonomy's shape.
        self::assertCount(1, $crawler->filter('.tx-sketch'));
        self::assertStringContainsString('Southern Reserve has no kinds of incident yet', $crawler->text());
        // One honest way in: a real form that writes the first kind.
        self::assertCount(1, $crawler->filter('.tx-empty form[action$="/incidents/kinds"]'));
    }

    /** The copy-from-area picker is deferred, so the empty start offers no such live control. */
    public function testTheEmptyStartDoesNotShipACopyFromAreaPicker(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->kindsUrl($area));

        self::assertCount(0, $crawler->filter('.tx-copy'));
        self::assertCount(0, $crawler->filter('.tx-area'));
    }

    // ── the manage gate ────────────────────────────────────────────────────────

    public function testManagingTheTaxonomyNeedsTheManagePermission(): void
    {
        $area = $this->anArea();
        // A reporter may file, and may NOT manage — the split the module rests on.
        $this->client->loginUser($this->aReporter());

        $this->client->request('GET', $this->kindsUrl($area));

        self::assertResponseStatusCodeSame(403);
    }

    // ── the populated manager ────────────────────────────────────────────────

    public function testCreatingTheFirstKindMovesTheScreenToTheManager(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->kindsUrl($area))->html();
        $this->client->request('POST', $this->kindsUrl($area), [
            '_token' => $this->tokenFrom($html),
            'label' => 'Poaching & wildlife crime',
            'colour' => 'poach',
        ]);

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        self::assertCount(1, $crawler->filter('.tx-mgr'));
        self::assertCount(0, $crawler->filter('.tx-empty'));
        self::assertStringContainsString('Poaching & wildlife crime', $crawler->filter('.tx-kinds')->text());
        // The wire-code chip the register and exports hold.
        self::assertStringContainsString('poaching-wildlife-crime', $crawler->filter('.tx-detail')->text());
    }

    public function testTheManagerRendersSubsTheirBlocksAndTheToggleEditor(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Poaching');
        $sub = $this->admin()->createSubcategory($kind, 'Snaring');
        $this->admin()->setBlocks($sub, [BehaviorBlockEnum::Species, BehaviorBlockEnum::Counts]);
        $this->client->loginUser($this->aManager());

        // The sub row and its two block chips.
        $crawler = $this->client->request('GET', $this->kindsUrl($area).'?kind='.$kind->getUuid()->toRfc4122());
        self::assertStringContainsString('Snaring', $crawler->filter('.tx-sub')->text());
        self::assertGreaterThanOrEqual(2, $crawler->filter('.tx-sub .tx-blk.on')->count());

        // Opening the block editor shows all twelve composable toggles.
        $editor = $this->client->request('GET', $this->kindsUrl($area).'?kind='.$kind->getUuid()->toRfc4122().'&blocks='.$sub->getUuid()->toRfc4122());
        self::assertCount(1, $editor->filter('.tx-blockedit'));
        self::assertCount(\count(BehaviorBlockEnum::cases()), $editor->filter('.tx-toggles .tx-tog input[type="checkbox"]'));
        // The two already-on blocks are checked.
        self::assertCount(2, $editor->filter('.tx-toggles input[checked]'));
    }

    /**
     * AND THE CHECKBOX IS THE ONLY THING THAT SAYS SO. A switched-on block is
     * rendered checked and nothing else: the sheet lights the toggle from
     * `:has(input:checked)`, so unticking one goes dark where a person clicked.
     * A second, server-written `on` class would hold the toggle lit until the
     * next page load and read as a block that refuses to be switched off.
     */
    public function testASwitchedOnBlockIsSaidByItsCheckboxAlone(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Poaching');
        $sub = $this->admin()->createSubcategory($kind, 'Snaring');
        $this->admin()->setBlocks($sub, [BehaviorBlockEnum::Species, BehaviorBlockEnum::Counts]);
        $this->client->loginUser($this->aManager());

        $editor = $this->client->request(
            'GET',
            $this->kindsUrl($area).'?kind='.$kind->getUuid()->toRfc4122().'&blocks='.$sub->getUuid()->toRfc4122(),
        );

        self::assertCount(2, $editor->filter('.tx-toggles input[checked]'));
        self::assertCount(
            0,
            $editor->filter('.tx-toggles .tx-tog.on'),
            'The server writes no lit class on a block toggle: the checkbox carries the state.',
        );
    }

    /**
     * ONE PANEL, ONE SAVE. The blocks, the direction money runs and the term the
     * word promises are one decision about one word, so the editor writes all
     * three in a single POST. WHAT THE FORM ASKS IS NOT ON THE PANEL AT ALL: the
     * questions come from the blocks, and a word cannot invent one.
     */
    public function testComposingAWordsBehaviourThroughTheEditorPersists(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Conflict');
        $sub = $this->admin()->createSubcategory($kind, 'Livestock depredation');
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->kindsUrl($area).'?kind='.$kind->getUuid()->toRfc4122().'&blocks='.$sub->getUuid()->toRfc4122())->html();
        $this->client->request('POST', $this->kindsUrl($area).'/subcategories/'.$sub->getUuid()->toRfc4122().'/behaviour', [
            '_token' => $this->tokenFrom($html),
            'blocks' => ['species', 'money'],
            'money_direction' => 'compensation',
            'term_hours' => '720',
            // Whatever a hand-made request carries under this name, the editor no
            // longer has a field list to write it to.
            'fields' => 'Species, Livestock lost',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->getRepository(TaxonomySubcategory::class)->findOneBy(['uuid' => $sub->getUuid()]);
        self::assertNotNull($stored);
        self::assertTrue($stored->hasBlock(BehaviorBlockEnum::Species));
        self::assertTrue($stored->carriesMoney());
        self::assertSame('compensation', $stored->getMoneyDirection()?->value);

        // The term is this word's own, and reads as the design writes it.
        self::assertSame(720, $stored->getTermHours());
        self::assertSame('30 d', $stored->termLabel());

        // THERE IS NO FIELD LIST TO WRITE TO. A word's questions are its blocks'.
        self::assertFalse(property_exists($stored, 'fieldSet'), 'A word still carries a list of questions of its own.');
    }

    /** The term is on the panel, and nothing on the panel asks for a field name. */
    public function testTheBehaviourPanelDrawsTheTermAndAsksForNoFields(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Conflict');
        $sub = $this->admin()->createSubcategory($kind, 'Livestock depredation');
        $this->client->loginUser($this->aManager());

        $editor = $this->client->request('GET', $this->kindsUrl($area).'?kind='.$kind->getUuid()->toRfc4122().'&blocks='.$sub->getUuid()->toRfc4122());

        self::assertCount(1, $editor->filter('.tx-blockedit input[name="term_hours"]'));
        self::assertCount(0, $editor->filter('.tx-blockedit input[name="fields"]'));
        self::assertStringNotContainsString('Fields this word asks for', $editor->html());
    }

    /**
     * THE SUB-CATEGORY ROW CARRIES ITS BLOCKS AND ITS TERM, AND NO COUNT OF
     * FIELDS. A count of fields would be a count of something the product does not
     * have: a word's questions are its blocks' questions.
     */
    public function testTheSubcategoryRowCarriesBlockChipsAndNoFieldCount(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Conflict');
        $sub = $this->admin()->createSubcategory($kind, 'Livestock depredation');
        $this->admin()->setBlocks($sub, [BehaviorBlockEnum::Species, BehaviorBlockEnum::Money], MoneyDirectionEnum::Compensation);
        $this->client->loginUser($this->aManager());

        // A second word, carrying no money block at all — the row says so rather
        // than leaving a reader to notice an absence.
        $this->admin()->setBlocks(
            $this->admin()->createSubcategory($kind, 'Crop raiding'),
            [BehaviorBlockEnum::Counts],
        );

        $page = $this->client->request('GET', $this->kindsUrl($area).'?kind='.$kind->getUuid()->toRfc4122());
        $rows = $page->filter('.tx-sub')->each(
            static fn ($row): array => $row->filter('.blocks .tx-blk')->each(static fn ($chip): string => $chip->text()),
        );

        self::assertSame([
            ['Species', 'Money · compensation', 'term 72 h'],
            ['Counts', 'no money block', 'term 72 h'],
        ], $rows);
        self::assertStringNotContainsString('fields', $page->filter('.tx-sub')->text());
        // The term chip wears the design's own class, not the plain chip's.
        self::assertCount(2, $page->filter('.tx-sub .blocks .tx-blk.term'));
    }

    // ── deactivate never deletes ─────────────────────────────────────────────

    public function testDeactivatingAKindDimsItInPlaceAndReactivateBringsItBack(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Fire');
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->kindsUrl($area))->html();
        $this->client->request('POST', $this->kindsUrl($area).'/'.$kind->getUuid()->toRfc4122().'/deactivate', [
            '_token' => $this->tokenFrom($html),
        ]);
        $crawler = $this->client->followRedirect();

        // Still there, dimmed — never hidden, never deleted.
        self::assertCount(1, $crawler->filter('.tx-kind.off'));
        self::assertSame(1, $this->kindCount($area));

        // And it reactivates in one click.
        $this->client->request('POST', $this->kindsUrl($area).'/'.$kind->getUuid()->toRfc4122().'/reactivate', [
            '_token' => $this->tokenFrom($crawler->html()),
        ]);
        $back = $this->client->followRedirect();
        self::assertCount(0, $back->filter('.tx-kind.off'));
    }

    /** There is no delete control anywhere on the page. */
    public function testThereIsNoDeleteControlAnywhere(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Poaching');
        $this->admin()->createSubcategory($kind, 'Snaring');
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->kindsUrl($area))->html();

        self::assertStringNotContainsStringIgnoringCase('/delete', $html);
        self::assertStringNotContainsStringIgnoringCase('>Delete<', $html);
    }

    // ── area scope ───────────────────────────────────────────────────────────

    public function testOneAreasKindsNeverAppearInAnother(): void
    {
        $northern = $this->anArea('Northern Reserve');
        $southern = $this->anArea('Southern Reserve');
        $this->admin()->createKind($northern, 'Poaching');
        $this->client->loginUser($this->aManager());

        // Southern Reserve sees its own (empty) list, not Northern Reserve's kind.
        $crawler = $this->client->request('GET', $this->kindsUrl($southern));
        self::assertCount(1, $crawler->filter('.tx-empty'));
        self::assertStringNotContainsString('Poaching', $crawler->filter('.tx-empty')->text());
    }

    /** A sub-category of another area is a 404 on this area's write route — no reach across. */
    public function testWritingToAnotherAreasRowIs404(): void
    {
        $northern = $this->anArea('Northern Reserve');
        $southern = $this->anArea('Southern Reserve');
        $kind = $this->admin()->createKind($northern, 'Poaching');
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->kindsUrl($southern))->html();
        // Northern Reserve's kind uuid, posted at Southern Reserve's URL.
        $this->client->request('POST', $this->kindsUrl($southern).'/kinds/'.$kind->getUuid()->toRfc4122().'/deactivate', [
            '_token' => $this->tokenFrom($html),
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // ── per-area uniqueness, at the door ─────────────────────────────────────

    public function testADuplicateKindLabelIsRefusedWithoutCreatingASecondRow(): void
    {
        $area = $this->anArea();
        $this->admin()->createKind($area, 'Fire');
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->kindsUrl($area))->html();
        $this->client->request('POST', $this->kindsUrl($area), [
            '_token' => $this->tokenFrom($html),
            'label' => 'fire',
            'colour' => 'mort',
        ]);

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        // The refusal is flashed, and no second row was written.
        self::assertStringContainsString('already has a kind called', $crawler->text());
        self::assertSame(1, $this->kindCount($area));
    }

    // ── CSRF ───────────────────────────────────────────────────────────────────

    public function testAWriteWithoutACsrfTokenIsRefused(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aManager());

        $this->client->request('POST', $this->kindsUrl($area), [
            'label' => 'Poaching',
            'colour' => 'poach',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->kindCount($area));
    }
}
