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
use Uhifadhi\Incident\Enum\IncidentSeverityEnum;

/**
 * THE DASHBOARD, rendered. Every widget the module ships is drawn against real
 * rows here — a template that referenced a variable the model does not carry
 * fails on this test rather than in front of a warden.
 */
final class DashboardPageTest extends FunctionalTestCase
{
    public function testTheShippedCompositionRenders(): void
    {
        $area = $this->anAreaWithKinds();
        $this->aZone($area, 'North Gate');
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        // The composition the module ships with: the counts, then where, then
        // what, then the money.
        self::assertCount(1, $crawler->filter('[data-w="kpis"]'));
        self::assertCount(1, $crawler->filter('[data-w="register"]'));
        self::assertCount(1, $crawler->filter('[data-w="map"]'));
        self::assertCount(1, $crawler->filter('[data-w="money"]'));
        // …and nothing else, because a widget that is off is ABSENT.
        self::assertCount(0, $crawler->filter('[data-w="board"]'));
        self::assertSelectorTextContains('h1.pg', 'Incidents');

        /*
         * THE TAB TITLE NAMES THE AREA EXACTLY ONCE.
         *
         * The shell's document composes it as page — place — brand, where the
         * place is the area the request is in, so a page that names the area
         * itself prints it twice ("Sample Area — Incidents — Sample Area —
         * Uhifadhi"). Every screen of this module did, which is what a
         * `layout.html.twig` that composed nothing left behind.
         *
         * The rule is pinned rather than the string, so it holds for any area in
         * any installation.
         */
        self::assertSame(1, substr_count($crawler->filter('title')->first()->text(), $area->getName() ?? ''));
    }

    /**
     * EVERY WIDGET THE MODULE SHIPS renders on real data. The dashboard only
     * draws four of them by default, so this walks the whole catalogue through
     * the widget library, which renders every one at full size.
     */
    public function testEveryWidgetInTheCatalogueRenders(): void
    {
        $area = $this->anAreaWithKinds();
        $this->aZone($area, 'North Gate');
        $reporter = $this->aReporter();
        $this->anIncident($area, reportedBy: $reporter);
        $this->anIncident($area, 'roadkill', 'Zebra roadkill on the C-road, km 12', $reporter);
        $this->client->loginUser($reporter);

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/widgets', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        foreach ([
            'kpis', 'register', 'queue', 'report', 'maplist', 'map', 'zones', 'trend', 'bycat',
            'severity', 'spark', 'feed', 'evidence', 'categories', 'matrix', 'money', 'board',
            'sla', 'funnel', 'rail',
        ] as $widget) {
            self::assertGreaterThan(
                0,
                $crawler->filter(\sprintf('[data-w="%s"]', $widget))->count(),
                \sprintf('The "%s" widget did not render in the library.', $widget),
            );
        }
    }

    /**
     * THE PORTED WIDGET MARKUP MATCHES THE DESIGN. Each of these is a design
     * element the app had drifted from — the KPI strip's own grid, the matrix's
     * heat cells / expander / grand-total row, the map+results docked .i-hit list,
     * and the status board's card footer. Rendered through the library, which
     * draws every widget at full size on real rows.
     */
    public function testThePortedWidgetMarkupMatchesTheDesign(): void
    {
        $area = $this->anAreaWithKinds();
        $this->aZone($area, 'North Gate');
        $reporter = $this->aReporter();
        $this->anIncident($area, 'snaring', 'Snare line lifted at the Acacia Wood forest edge', $reporter);
        $this->anIncident($area, 'livestock-depredation', 'Lion killed four goats at Riverside', $reporter);
        $this->client->loginUser($reporter);

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/widgets', $this->uuidOf($area)));
        self::assertResponseIsSuccessful();

        // The KPI strip carries its own equal-columns grid class.
        self::assertGreaterThan(0, $crawler->filter('[data-w="kpis"].kstrip')->count());

        // The matrix draws heat cells with a status caption, a sub-category
        // expander on each row header, and a grand-total row.
        self::assertGreaterThan(0, $crawler->filter('[data-w="matrix"] a.cell em')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-w="matrix"] .i-mxrow .i-mxexp')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-w="matrix"] tr.tot .cell')->count());

        // The map+results list is a docked .i-hit list under an "In this view" head.
        self::assertGreaterThan(0, $crawler->filter('[data-w="maplist"] .i-listhd')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-w="maplist"] a.i-hit .r1 .id')->count());

        // And each hit names the mark it spotlights: the layer its category is
        // drawn on, and its own reference. One attribute, no map JavaScript.
        self::assertMatchesRegularExpression(
            '/^incident\.[a-z-]+:INC-\d+$/',
            $crawler->filter('[data-w="maplist"] a.i-hit')->first()->attr('data-atlas-highlight') ?? '',
        );

        // The status board's card footer is the .ft row with the zone chip.
        self::assertGreaterThan(0, $crawler->filter('[data-w="board"] .i-card .ft .i-zone')->count());
    }

    /**
     * AN OVERVIEW CARD NEVER GROWS WITH THE DATA. The list widgets cap to the
     * latest that fits and say "N of M" — the feed at 14, the queue at 8 — and the
     * status board closes a deep column with a "… and N more" ghost rather than
     * running down the page. (Register and map+results already cap at 14; evidence
     * and SLA are capped in the service.).
     */
    public function testOverviewListWidgetsDoNotGrowWithTheData(): void
    {
        $area = $this->anAreaWithKinds();
        $reporter = $this->aReporter();
        // Sixteen reported-by-me incidents: past every cap (feed 14, queue 8,
        // board column 6) and all in the one "reported" column.
        for ($i = 0; $i < 16; ++$i) {
            $this->anIncident($area, 'snaring', \sprintf('Snare line %d lifted at the forest edge', $i), $reporter);
        }
        $this->client->loginUser($reporter);

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/widgets', $this->uuidOf($area)));
        self::assertResponseIsSuccessful();

        // Feed: the latest 14, grouped by day, and it says "latest 14 of 16".
        $feed = $crawler->filter('[data-w="feed"]')->first();
        self::assertCount(14, $feed->filter('a.i-feed'));
        self::assertStringContainsString('latest 14 of 16', $feed->filter('.tab')->text());

        // Queue: the oldest 8, and it says "8 of 16".
        $queue = $crawler->filter('[data-w="queue"]')->first();
        self::assertCount(8, $queue->filter('.i-queuerow'));
        self::assertStringContainsString('8 of 16', $queue->filter('.tab')->text());

        // Board: the reported column shows 6 cards and a "… and 10 more" ghost;
        // the header count stays the true total.
        $board = $crawler->filter('[data-w="board"]')->first();
        self::assertGreaterThan(0, $board->filter('.i-card.ghost')->count());
        self::assertStringContainsString('and 10 more', $board->text());
    }

    /**
     * THE MAP IS THE ATLAS'S PLATE, AND ITS LEGEND SWITCHES ITS LAYERS.
     *
     * The plate — the control stack, the floating legend, fullscreen — is drawn
     * by the atlas and styled by its map.css, which this module's base template
     * must LINK or the controls render invisible. This module states the layers:
     * one per category, plus the area's zones, each row naming what it switches.
     */
    public function testTheMapIsThePlatesAndItsLegendSwitchesItsLayers(): void
    {
        $area = $this->anAreaWithKinds();
        $this->aZone($area, 'North Gate');
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));
        self::assertResponseIsSuccessful();

        // The atlas's one map stylesheet is linked, so the plate, its chrome and
        // its legend are styled rather than invisible. The stem, not the
        // filename: AssetMapper content-digests what a bundle's public/ dir
        // serves, exactly as it does in an installation.
        self::assertGreaterThan(0, $crawler->filter('link[href*="atlas/map"]')->count());

        // The plate itself, wearing the atlas's one map controller.
        $plate = $crawler->filter('[data-w="map"] .map-plate');
        self::assertCount(1, $plate);
        self::assertSame('uhifadhi--atlas-bundle--map-plate', $plate->attr('data-controller'));

        // The legend the plate rendered: a row per layer, each a real switch.
        self::assertGreaterThan(0, $crawler->filter('[data-w="map"] .map-legend .lay')->count());
        self::assertStringContainsString('Zones', $crawler->filter('[data-w="map"] .map-legend')->text());
    }

    /**
     * A MARK CARRIES WHAT IT SAYS AND WHERE IT GOES.
     *
     * The line a hover prints and the url a popup links to are the incident's
     * own properties, travelling in the payload the plate reads — so the atlas
     * binds the tooltip and writes the popup, and this module ships no map
     * JavaScript to do either.
     */
    public function testEveryMarkCarriesItsHoverLineAndItsCaseFileUrl(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));
        self::assertResponseIsSuccessful();

        // Read as the browser would: the atlas's own key of UX Map's extra
        // payload, decoded, rather than a substring of the markup.
        $extra = json_decode(
            $crawler->filter('[data-w="map"] .map-canvas')->first()->attr('data-symfony--ux-leaflet-map--map-extra-value') ?? '',
            true,
        );
        self::assertIsArray($extra);
        $properties = self::firstMarkOf($extra);

        self::assertIsString($properties['summary']);
        self::assertStringStartsWith($incident->getReference().' · ', $properties['summary']);
        self::assertSame(
            \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $incident->getReference()),
            $properties['href'],
        );
    }

    /**
     * The properties of the one mark on the plate.
     *
     * @param array<array-key, mixed> $extra
     *
     * @return array<array-key, mixed>
     */
    private static function firstMarkOf(array $extra): array
    {
        $atlas = $extra['atlas'] ?? null;
        self::assertIsArray($atlas);
        $layers = $atlas['layers'] ?? null;
        self::assertIsArray($layers);

        foreach ($layers as $layer) {
            $collection = \is_array($layer) ? ($layer['features'] ?? null) : null;
            $features = \is_array($collection) ? ($collection['features'] ?? null) : null;
            $first = \is_array($features) ? ($features[0] ?? null) : null;
            $properties = \is_array($first) ? ($first['properties'] ?? null) : null;
            if (\is_array($properties) && isset($properties['summary'])) {
                return $properties;
            }
        }

        self::fail('No mark reached the plate.');
    }

    /**
     * THE MAP-FIRST CHARTS REACH THE ATLAS WITH REAL ROWS.
     *
     * THE DRAWING IS NOT THIS MODULE'S ANY MORE, so what is asserted is not a
     * path or an arc: it is that each widget hands the component a chart, and
     * that the figures crossing to the browser are the ones these two filings
     * made. A widget that hard-coded the design's numbers, or named a figure
     * the model does not carry, still fails here.
     */
    public function testTheMapFirstChartsDrawFromData(): void
    {
        $area = $this->anAreaWithKinds();
        $reporter = $this->aReporter();
        $this->anIncident($area, 'snaring', 'Snare line lifted at the Acacia Wood forest edge', $reporter);
        $this->anIncident($area, 'livestock-depredation', 'Lion killed four goats at Riverside', $reporter);
        $this->client->loginUser($reporter);

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/widgets', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();

        // Each of the three is the atlas's chart card, not markup of this
        // module's: the plate, its fixed box and the library's canvas in it.
        foreach (['trend', 'bycat', 'severity'] as $widget) {
            self::assertGreaterThan(
                0,
                $crawler->filter(\sprintf('[data-w="%s"] .chart-plate .chart-box canvas', $widget))->count(),
                $widget.' draws through atlas_chart(), or it draws nothing.',
            );
        }

        // THE TWO KINDS FILED ARE THE TWO BANDS OF THE SHARE, each carrying its
        // own category rather than a colour this module picked.
        $share = $this->chartView($crawler, 'bycat');
        self::assertCount(2, $share['datasets']);
        self::assertSame('var(--cat-1)', $share['datasets'][0]['backgroundColor']);

        // And the severity chart states every level the model has, even at
        // nought, so the bars never collapse on a calm month.
        $severity = $this->chartView($crawler, 'severity');
        self::assertCount(4, $severity['labels']);
        self::assertEqualsWithDelta(2.0, array_sum($severity['datasets'][0]['data']), 0.0001);
    }

    /**
     * THE MONTH IS THE ATLAS'S GRID, FED BY THIS MODULE.
     *
     * One mark per incident filed in the window, in the cell for the day it was
     * filed on, leading to its case file — and the grid, the day heads and the
     * cell's fixed height are the component's. A module that laid out its own
     * month would pass every assertion above and still have to decide how many
     * marks fit in a square, which it cannot know.
     */
    public function testTheCalendarDrawsAMarkPerFiling(): void
    {
        $area = $this->anAreaWithKinds();
        $reporter = $this->aReporter();
        $this->anIncident($area, 'snaring', 'Snare line lifted at the Acacia Wood forest edge', $reporter);
        $this->anIncident($area, 'livestock-depredation', 'Lion killed four goats at Riverside', $reporter);
        $this->client->loginUser($reporter);

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();

        // The component's own grid: whole weeks of cells, with the day heads
        // above them. Five rows or six, never a ragged last line.
        self::assertCount(7, $crawler->filter('[data-w="cal"] .cal .dh'));
        self::assertGreaterThanOrEqual(35, $crawler->filter('[data-w="cal"] .cal .dc')->count());

        // And this module's contribution: a mark per filing, each one a link
        // into the case file it is about.
        $marks = $crawler->filter('[data-w="cal"] .cal a.cal-mark');
        self::assertCount(2, $marks);
        self::assertStringContainsString('/modules/incidents/', (string) $marks->first()->attr('href'));
    }

    /**
     * WHAT ONE WIDGET'S CHART ACTUALLY SENDS THE BROWSER — the view UX Chart.js
     * writes onto its canvas, which is the one place a figure computed in PHP
     * becomes a figure a reader sees.
     *
     * @return array{labels: list<string>, datasets: list<array{data: list<float>, backgroundColor: string}>}
     *
     * @see vendor/symfony/ux-chartjs/src/Model/Chart.php — createView() wraps the
     *      data in {type, data, options}; what a module stated is under `data`
     */
    private function chartView(Crawler $crawler, string $widget): array
    {
        $canvas = $crawler->filter(\sprintf('[data-w="%s"] .chart-plate canvas', $widget))->first();
        $payload = $canvas->attr('data-symfony--ux-chartjs--chart-view-value');
        self::assertIsString($payload, 'The canvas carries the chart the module stated.');

        /** @var array{data: array{labels: list<string>, datasets: list<array{data: list<float>, backgroundColor: string}>}} $view */
        $view = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);

        return $view['data'];
    }

    /**
     * THE SEVERITY CHIP WEARS ITS LEVEL'S CLASS. A critical incident renders the
     * new top step — `.i-sev.crit` labelled "critical" — proving the register
     * reads all four levels, not the old three.
     */
    public function testTheRegisterChipShowsTheCriticalLevel(): void
    {
        $area = $this->anAreaWithKinds();
        $reporter = $this->aReporter();
        $this->anIncident($area, 'snaring', 'Snare line lifted at the Acacia Wood forest edge', $reporter, IncidentSeverityEnum::Critical);
        $this->client->loginUser($reporter);

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-w="register"] .i-sev.crit'));
        self::assertSame('critical', $crawler->filter('[data-w="register"] .i-sev.crit')->text());
    }

    /** An area with nothing filed still gets a whole dashboard, not an error. */
    public function testAnEmptyAreaRendersAWholeDashboard(): void
    {
        $area = $this->anAreaWithKinds('Quiet Area');
        $this->client->loginUser($this->aReporter());

        $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-w="kpis"]', '0');
    }

    /**
     * ONE FILTER DRIVES EVERYTHING. Narrowing to a category narrows the register
     * AND the counts, because they are one query read twice.
     */
    public function testACategoryChipNarrowsTheWholePage(): void
    {
        $area = $this->anAreaWithKinds();
        $reporter = $this->aReporter();
        $this->anIncident($area, 'livestock-depredation', 'Lion killed four goats at Riverside', $reporter);
        $this->anIncident($area, 'snaring', 'Snare line lifted at the Acacia Wood forest edge', $reporter);
        $this->client->loginUser($reporter);

        $all = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));
        self::assertCount(2, $all->filter('[data-w="register"] table tr')->reduce(
            static fn ($node) => str_contains((string) $node->attr('class'), '') && $node->filter('.i-id')->count() > 0,
        ));

        $narrowed = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents?category=poaching', $this->uuidOf($area)));
        self::assertCount(1, $narrowed->filter('[data-w="register"] .i-id'));
        self::assertStringContainsString('Snare line', $narrowed->filter('[data-w="register"]')->text());
    }

    /**
     * THE SEARCH BOX DRIVES THE SAME ONE QUERY. Typing a word narrows the
     * register the way a category does — the box is server-side, wired through
     * the filter, not a client-side hide.
     */
    public function testTheSearchBoxNarrowsTheWholePage(): void
    {
        $area = $this->anAreaWithKinds();
        $reporter = $this->aReporter();
        $this->anIncident($area, 'livestock-depredation', 'Lion killed four goats at Riverside', $reporter);
        $this->anIncident($area, 'snaring', 'Snare line lifted at the Acacia Wood forest edge', $reporter);
        $this->client->loginUser($reporter);

        // The box itself is on the page, once per reading of the register.
        $all = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));
        self::assertGreaterThan(0, $all->filter('.lfilt input[name="q"]')->count());

        $narrowed = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents?q=goats', $this->uuidOf($area)));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $narrowed->filter('[data-w="register"] .i-id'));
        self::assertStringContainsString('goats', $narrowed->filter('[data-w="register"]')->text());
        // The box keeps what was typed, so a person sees their own query.
        self::assertSame('goats', $narrowed->filter('.lfilt input[name="q"]')->first()->attr('value'));
    }

    /**
     * THE FILTER BAR IS FOUR DROPDOWNS — category, status, zone and month — plus
     * the search box. The category dropdown collapses the all/kind chips (with hue
     * dots and counts) into one; each option is a REAL link driving the one query.
     */
    public function testTheFilterBarIsFiveDropdowns(): void
    {
        $area = $this->anAreaWithKinds();
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));
        self::assertResponseIsSuccessful();

        // No bare select — the bar is dropdowns. FIVE of them: the lens joined
        // them when it stopped being a row of its own above the page.
        self::assertCount(0, $crawler->filter('.lfilt select[name="category"]'));
        $register = $crawler->filter('[data-w="register"] .lfilt')->first();
        self::assertCount(5, $register->filter('.i-dd'));

        // AND EACH IS THE CHAIN'S OWN CONTROL: a <details> whose <summary> is
        // the chip. The dropdown is the shell's, chrome and behaviour both, so
        // it opens with no script of this module's and this module's sheet
        // restates none of its rules.
        self::assertCount(5, $register->filter('details.i-dd > summary.mchip.i-ddt'));
        self::assertCount(0, $register->filter('[data-controller*="incident-filters"], [data-action*="incident-filters"]'));

        // Category dropdown: an "all" option plus one option per kind, each a
        // real link carrying its count and saying which of the house's nine
        // hues the kind wears — poaching is the first word this area wrote.
        self::assertGreaterThan(0, $register->filter('.i-dd .i-ddmenu a.i-ddopt .i-dot[data-cat="1"]')->count());
        self::assertGreaterThan(0, $register->filter('.i-dd a.i-ddopt[href*="category=poaching"]')->count());
        // Status and month options drive their own params.
        self::assertGreaterThan(0, $register->filter('.i-dd a.i-ddopt[href*="status="]')->count());
        self::assertGreaterThan(0, $register->filter('.i-dd a.i-ddopt[href*="month="]')->count());
        // A zone dropdown is present (its options are the zones that have incidents).
        self::assertCount(1, $register->filter('.i-ddmenu[aria-label="Filter by zone"]'));
        // The search box is preserved.
        self::assertGreaterThan(0, $register->filter('.lsearch input[name="q"]')->count());
    }

    /**
     * THE DROPDOWNS DRIVE REAL FILTERING. Selecting a status, a zone or a month is
     * an ordinary link to the one query — the register re-queries server-side, the
     * same as the category dropdown, and the trigger then shows the active choice.
     */
    public function testTheDropdownsFilterForReal(): void
    {
        $area = $this->anAreaWithKinds();
        $reporter = $this->aReporter();
        $this->anIncident($area, 'livestock-depredation', 'Lion killed four goats at Riverside', $reporter);
        $this->client->loginUser($reporter);

        // The "reported" status option's own link narrows the register — the seeded
        // incident is reported, so it stays; the trigger then reads "reported".
        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));
        $statusHref = $crawler->filter('[data-w="register"] .i-dd a.i-ddopt[href*="status=reported"]')->first()->attr('href');
        $byStatus = $this->client->request('GET', (string) $statusHref);
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $byStatus->filter('[data-w="register"] .i-id')->count());
        self::assertStringContainsString('reported', $byStatus->filter('[data-w="register"] .lfilt')->first()->text());

        // A month with nothing filed shows an empty register — the month param is real.
        $empty = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents?month=2020-01', $this->uuidOf($area)));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $empty->filter('[data-w="register"] .i-id'));
        self::assertStringContainsString('january 2020', $empty->filter('[data-w="register"] .lfilt')->first()->text());

        // The zone param is wired: the trigger reflects it even where geometry left
        // the register empty, proving the dropdown drives ?zone=.
        $byZone = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents?zone=%s', $this->uuidOf($area), rawurlencode('Highland Ward')));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Highland Ward', $byZone->filter('[data-w="register"] .lfilt')->first()->text());
    }

    /**
     * THE LENS IS A LENS, NOT A FENCE. Whatever it selects, "Every category" is
     * always one click away and shows the whole register to anybody.
     */
    public function testTheLensChipAlwaysOffersTheWholeList(): void
    {
        $area = $this->anAreaWithKinds();
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents?lens=ecology', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        // "Every category" is present whatever the lens, so nothing is fenced off.
        $all = $crawler->filter('.lfilt .i-ddmenu[aria-label="Order by department lens"] a[data-lens="all"]')->first();
        self::assertGreaterThan(0, $all->count());
        self::assertSame('Every category', trim($all->filter('.i-ddopt-l')->text()));
    }

    /**
     * A DOOR THE VIEWER CANNOT OPEN IS NOT DRAWN.
     *
     * "Report incident" opens the screen that CREATES an incident, and that
     * screen enforces `incidents.record`. The dashboard was deciding
     * whether to draw the control from `incident.record_screens` — a
     * compile-time parameter answering a different question: whether the route
     * EXISTS in this installation, which it does wherever SecurityBundle is
     * registered. So the page asked about the installation and printed the
     * answer as if it were about the person.
     *
     * A control the viewer may not have is ABSENT rather than greyed out: a
     * disabled button tells somebody a screen exists and they are not trusted
     * with it, while a live link that fails tells them nothing until the click
     * is gone.
     */
    public function testTheReportControlIsOfferedOnlyToSomebodyWhoMayFile(): void
    {
        $area = $this->anAreaWithKinds();
        $this->anIncident($area);

        // Staff, signed in, holding neither permission — the shape of the very
        // first person an installation adds after the administrator.
        $this->client->loginUser($this->aUser('bystander@example.test', 'Neema', 'Kimaro'));
        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.pgact a[href$="/incidents/new"]'));

        // …and the reporter, who may, is handed it.
        $this->client->loginUser($this->aReporter());
        $offered = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertCount(1, $offered->filter('.pgact a[href$="/incidents/new"]'));
    }

    /**
     * THE SAME QUESTION INSIDE A WIDGET. The report entry card carries the same
     * door, and it may not answer differently from the header above it.
     */
    public function testTheReportCardsControlAsksTheSameQuestion(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aUser('bystander@example.test', 'Neema', 'Kimaro'));

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href$="/incidents/new"]'));
    }

    /**
     * THE HEADER'S OWN ACTIONS, AND NO CONFIGURATION BUTTON AMONG THEM. There is
     * one configuration entry per surface — the shell's `Configure` action — so
     * a kinds link and a Widget library button in this module's action row is
     * exactly what the frame replaced. What is left is the file and the filing.
     */
    public function testTheHeaderDrawsTheExportAndTheFilingAndNoConfigurationButton(): void
    {
        $area = $this->anAreaWithKinds();
        $this->anIncident($area);
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        $actions = $crawler->filter('.pgact');
        self::assertGreaterThan(0, $actions->filter('a[href*="/incidents/export.csv"]')->count());
        self::assertCount(1, $actions->filter('a[href$="/incidents/new"]'));

        // The configuration doors are gone from here, every one of them.
        self::assertCount(0, $actions->filter('a[href$="/incidents/kinds"]'));
        self::assertCount(0, $actions->filter('a[href$="/incidents/taxonomy"]'));
        self::assertCount(0, $actions->filter('a[href$="/incidents/widgets"]'));

        $html = $actions->html();
        self::assertLessThan(
            strpos($html, '/incidents/new'),
            strpos($html, '/incidents/export.csv'),
            'The file comes before the filing, as the design draws them.',
        );
    }

    /**
     * THE READ-ONLY KINDS CARD — what this area files, with this month's count
     * under each kind, and one link out to the section that edits them.
     */
    public function testTheDashboardShowsTheKindsThisAreaFiles(): void
    {
        $area = $this->anAreaWithKinds();
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-incident-kinds]');
        self::assertCount(1, $card);
        self::assertGreaterThan(0, $card->filter('.kx-k')->count());
        self::assertStringContainsString('this month', $card->filter('.kx-h .n')->first()->text());
        self::assertStringContainsString('Edit in Configure', $card->filter('.kx-foot')->text());
        self::assertStringContainsString('/incidents/kinds', (string) $card->filter('.kx-foot a')->attr('href'));
    }

    /**
     * THE KINDS SCREEN IS STILL GATED, and the gate is the screen's own. The
     * dashboard offers nobody a door to it — the shell's one `Configure` action
     * does — so what is left to hold is that the screen refuses whoever may not
     * manage and opens for whoever may.
     */
    public function testTheKindsScreenIsOfferedOnlyToSomebodyWhoMayManage(): void
    {
        $area = $this->anAreaWithKinds();
        $this->anIncident($area);
        $kinds = \sprintf('/areas/%s/modules/incidents/kinds', $this->uuidOf($area));

        $this->client->loginUser($this->aReporter());
        $this->client->request('GET', $kinds);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->aManager());
        $this->client->request('GET', $kinds);
        self::assertResponseIsSuccessful();
    }

    /**
     * THE EXPORT DOOR CARRIES THE CURRENT FILTER. Narrow the register and the
     * download link narrows with it, so the file a person gets is the register they
     * were looking at — one filter, on the page and in the file alike.
     */
    public function testTheExportLinkCarriesTheCurrentFilter(): void
    {
        $area = $this->anAreaWithKinds();
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents?category=poaching&q=snare', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        $href = (string) $crawler->filter('.pgact a[href*="/incidents/export.csv"]')->first()->attr('href');
        self::assertStringContainsString('category=poaching', $href);
        self::assertStringContainsString('q=snare', $href);
    }

    /**
     * THE DASHBOARD HAS NO DOOR OF ITS OWN TO THE WIDGET LIBRARY. The tile at
     * the foot of a surface — "Add widgets — open the library" — was removed
     * from the designs: a surface ends with the last widget on it, and the
     * library is reached the way every other configuration screen is, through
     * the one quiet `Configure` action the shell draws in the page head from
     * this module's declared sections.
     *
     * Asserted here rather than left to the eye because the tile renders as a
     * perfectly ordinary cell: nothing about it fails until somebody looks.
     */
    public function testTheDashboardDrawsNoAddWidgetsDoorAtItsFoot(): void
    {
        $area = $this->anAreaWithKinds();
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.w-addtile'), 'the surface ends with its last widget, not with a door');
        self::assertStringNotContainsString('open the library', (string) $this->client->getResponse()->getContent());

        // …and the way there is still open: the shell's one Configure action,
        // whose widgets section IS the library.
        self::assertNotSame(
            0,
            $crawler->filter('.pgact a[href*="/incidents/configure"]')->count(),
            'the page head keeps the quiet Configure action the library is a section of',
        );
    }
}
