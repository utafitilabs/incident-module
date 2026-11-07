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
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\ShellBundle\Model\TablePage;

/**
 * THE FULL LIST — every incident the window holds, one page at a time, driven by
 * one GET: the filter row's chips, the search box and the pager all carry the
 * same question, and the count in the caption is the count of the rows under it.
 */
final class IncidentListPageTest extends FunctionalTestCase
{
    private const int PER_PAGE = 20;

    private function url(AreaOfInterest $area, string $query = ''): string
    {
        return \sprintf('/areas/%s/modules/incidents/incidents%s', $this->uuidOf($area), '' === $query ? '' : '?'.$query);
    }

    public function testItListsEveryIncidentWithTheDesignsColumns(): void
    {
        $area = $this->anAreaWithKinds();
        $this->aZone($area, 'North Gate');
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->url($area));

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['id', 'category', 'what happened', 'zone', 'status', 'severity', 'money', 'reported', ''],
            $crawler->filter('table.tbl thead th')->each(static fn (Crawler $th): string => trim($th->text())),
        );
        self::assertCount(1, $crawler->filter('table.tbl tr a.open-btn'));
    }

    /** The shell draws the strip from this module's declaration, list tab lit. */
    public function testTheShellDrawsTheModulesThreeDataPlaces(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->url($area));

        self::assertSame(
            ['Overview', 'Incidents', 'Incident kinds'],
            $crawler->filter('.atabs a')->each(static fn (Crawler $a): string => trim($a->text())),
        );
        self::assertSame('Incidents', trim($crawler->filter('.atabs a.on')->text()));
    }

    /** The same filter row the dashboard wears, with the lens among its chips. */
    public function testTheListWearsTheSameFilterRowAsTheDashboard(): void
    {
        $area = $this->anAreaWithKinds();
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->url($area));

        $row = $crawler->filter('.lfilt')->first();
        self::assertCount(5, $row->filter('.i-dd'));
        self::assertCount(5, $row->filter('details.i-dd > summary.mchip.i-ddt'));
        self::assertCount(1, $row->filter('.i-ddmenu[aria-label="Order by department lens"]'));
        self::assertCount(1, $row->filter('.lsearch input[name="q"]'));
        // Every option re-queries THIS page, never the dashboard.
        self::assertStringContainsString('/modules/incidents/incidents', (string) $row->filter('a.i-ddopt')->first()->attr('href'));
    }

    /** Twenty rows a page, and the Shell foot carries the rest of the answer. */
    public function testAPageHoldsTwentyRowsAndTheFootCarriesTheRest(): void
    {
        $area = $this->anAreaWithKinds();
        $reporter = $this->aReporter();
        for ($i = 0; $i < self::PER_PAGE + 3; ++$i) {
            $this->anIncident($area, 'livestock-depredation', 'Filed report number '.$i, $reporter);
        }
        $this->client->loginUser($reporter);

        $crawler = $this->client->request('GET', $this->url($area, 'status=reported'));

        self::assertCount(self::PER_PAGE, $crawler->filter('table.tbl tbody tr a.open-btn'));
        self::assertCount(0, $crawler->filter('.pgr'));
        $foot = $crawler->filter('.c > .rdf-foot');
        self::assertCount(1, $foot);
        self::assertStringStartsWith('23 incidents · 20 per page', trim($foot->filter('span')->first()->text()));
        self::assertStringContainsString('page 1 of 2', $foot->filter('.rdf-page')->text());

        $sizes = $foot->filter('details.rdf-size a.i-ddopt');
        self::assertSame(TablePage::SIZES, $sizes->each(static fn (Crawler $a): string => strtolower(trim($a->text()))));
        foreach ($sizes as $a) {
            \assert($a instanceof \DOMElement);
            self::assertStringContainsString('status=reported', $a->getAttribute('href'), 'A new size keeps the filter.');
            self::assertStringNotContainsString('page=', $a->getAttribute('href'), 'A new size starts again at page 1.');
        }

        $next = $foot->filter('.rdf-page a[aria-label="Next page"]');
        self::assertCount(1, $next);
        $crawler = $this->client->request('GET', (string) $next->attr('href'));

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('table.tbl tbody tr a.open-btn'));
        self::assertStringContainsString('page 2 of 2', $crawler->filter('.rdf-page')->text());

        $crawler = $this->client->request('GET', $this->url($area, 'per=all'));

        self::assertCount(self::PER_PAGE + 3, $crawler->filter('table.tbl tbody tr a.open-btn'));
        self::assertStringContainsString('all on one page', $crawler->filter('.rdf-foot')->text());
    }

    /** A page number is navigation: an unreadable one opens the first page, never a 400. */
    public function testAnUnreadablePageNumberOpensTheFirstPage(): void
    {
        $area = $this->anAreaWithKinds();
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->url($area, 'page=abc&per=7'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('page 1 of 1', $crawler->filter('.rdf-page')->text());
        self::assertStringContainsString('20 per page', $crawler->filter('.rdf-foot')->text());
    }

    /** Rows in the house shape: the source on the cell's `.sub` line, the door in its own cell. */
    public function testRowsAreTheHouseTableShape(): void
    {
        $area = $this->anAreaWithKinds();
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->url($area));

        $row = $crawler->filter('table.tbl tbody tr')->first();
        self::assertCount(1, $row->filter('td.i-what .sub'));
        self::assertCount(1, $row->filter('td.door a.open-btn'));
        self::assertCount(0, $crawler->filter('table.tbl tbody th'));
    }

    /** The search narrows the list, and the caption counts what it narrowed to. */
    public function testTheSearchNarrowsTheListAndItsCount(): void
    {
        $area = $this->anAreaWithKinds();
        $reporter = $this->aReporter();
        $this->anIncident($area, 'livestock-depredation', 'Lion killed four goats at Riverside', $reporter);
        $this->anIncident($area, 'roadkill', 'Zebra roadkill on the C-road, km 12', $reporter);
        $this->client->loginUser($reporter);

        $crawler = $this->client->request('GET', $this->url($area, 'q=zebra'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('table.tbl tr a.open-btn'));
        self::assertStringContainsString('1 incident', $crawler->filter('.c > .tab')->first()->text());
    }

    /** An empty answer says so rather than drawing an empty table. */
    public function testAnAnswerWithNoRowsSaysSo(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->url($area, 'q=nothing-here-at-all'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nothing matches this filter', $crawler->filter('table.tbl')->text());
    }

    /**
     * THE WORD "REGISTER" IS NOWHERE ON THE SURFACE — not in the address, not in
     * the title, not in a heading.
     */
    public function testNothingOnThePageSaysRegister(): void
    {
        $area = $this->anAreaWithKinds();
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $this->client->request('GET', $this->url($area));

        self::assertStringNotContainsStringIgnoringCase(
            'register',
            (string) $this->client->getResponse()->getContent(),
        );
    }
}
