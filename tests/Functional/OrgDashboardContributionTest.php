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

use Uhifadhi\Incident\UhifadhiIncidentBundle;

/**
 * THIS MODULE'S CELL AND FIGURE ON `/`, RENDERED BY THE CORE'S OWN PAGE.
 *
 * A CONTRIBUTION TEST PROVES THE PARTS; THIS PROVES THEY ARRIVE. The seam is
 * a tag, a partial pattern, a `by.<slug>` key and a stylesheet, and every one
 * of them is a string that can be right in this bundle and wrong where the
 * host reads it. So the dashboard is fetched over HTTP from the real
 * controller, with this module installed, and the cell is looked for in the
 * markup that came back.
 *
 * THE MODULE IS NEVER SWITCHED ON FOR THE ORGANIZATION. It is switched on per
 * AREA, and the dashboard is the sum of the areas — which is why the fixture
 * installs it in an area and then asks for a page that names none.
 */
final class OrgDashboardContributionTest extends FunctionalTestCase
{
    public function testTheModulesCellIsDrawnFromItsOwnPartialOnTheOrganizationDashboard(): void
    {
        $area = $this->anAreaWithKinds('North Range');
        $this->anIncident($area, title: 'Lion killed four goats at Riverside');
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();

        // THE CELL, and it is this module's: the id the shipped compositions
        // name, and the contributor tag that says whose figure it is.
        $cell = $crawler->filter('[data-w="incidents"]');
        self::assertCount(1, $cell, 'The organization dashboard draws this module’s cell.');
        self::assertSame('incidents', $cell->filter('.ao-by')->text(''));

        // DRAWN FROM THIS BUNDLE'S OWN PARTIAL, which is what the columns
        // prove: the host's template contains no widget markup at all, and no
        // other cell on the page states an incident against a term.
        self::assertSame(
            ['incident', 'area', 'kind', 'filed', 'state'],
            $cell->filter('table.tbl th')->each(static fn ($th) => $th->text('')),
        );
        self::assertStringContainsString('INC-0001', $cell->text(''));
        self::assertStringContainsString('North Range', $cell->text(''));
        self::assertCount(1, $cell->filter('.chip.ok'));

        // AND THE SHEET ITS CHIPS AND STATES COME FROM, linked by the host
        // because this contributor publishes it. Without it the cell renders
        // as browser defaults inside the host's page — the failure that put
        // blue underlined links on an area overview.
        //
        // The digest is AssetMapper's, so the assertion is on the path the
        // bundle publishes minus its extension: a version-stamped filename is
        // the installation's business and this module's constant is not.
        self::assertStringContainsString(
            substr(UhifadhiIncidentBundle::STYLESHEET, 0, -\strlen('.css')),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /**
     * THE FIGURE LANDS IN THE FOUR-TO-A-ROW STRIP, beside the host's own and
     * whatever else is installed — one tile per contributor, never a fifth.
     */
    public function testTheOpenIncidentsFigureLandsInTheFourToARowStrip(): void
    {
        $area = $this->anAreaWithKinds('North Range');
        $this->anIncident($area);
        $this->anIncident($area, 'crop-raiding', 'Elephants through the gardens overnight');
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();

        $strip = $crawler->filter('[data-w="kpis"]');
        self::assertCount(1, $strip);
        // FOUR, ALWAYS. A slot nobody contributed says nothing was measured;
        // it is never dropped and there is never a fifth.
        self::assertCount(4, $strip->filter('.c.kpi'));

        $figure = $strip->filter('.c.kpi')->reduce(
            static fn ($card) => str_contains($card->text(''), 'Open incidents'),
        );
        self::assertCount(1, $figure, 'This module publishes exactly one figure on the strip.');
        self::assertSame('2', $figure->filter('b.disp')->text(''));
        self::assertStringContainsString('2 filed today', $figure->filter('.sub')->text(''));
    }

    /**
     * ABSENT IS NOT ZERO, all the way to the page: an installation where
     * nothing has ever been filed gets the host's "nothing measured" slot and
     * no claim in this module's name.
     */
    public function testAnInstallationWithNothingFiledPublishesNoFigureAtAll(): void
    {
        $this->anAreaWithKinds('North Range');
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Open incidents', $crawler->filter('[data-w="kpis"]')->text(''));
        self::assertStringContainsString(
            'Nothing has been filed anywhere yet',
            $crawler->filter('[data-w="incidents"]')->text(''),
        );
    }
}
