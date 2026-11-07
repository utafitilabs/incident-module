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
use Symfony\Component\Routing\RouterInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Shell\OrgPage;
use Uhifadhi\Incident\Controller\IncidentOrgController;
use Uhifadhi\Incident\Enum\IncidentSeverityEnum;
use Uhifadhi\Incident\Module\IncidentModuleProvider;

/**
 * INCIDENTS AT ORGANIZATION SCOPE — two screens, read across every area
 * the module is switched on in: the register, which is where the module
 * lands, and the incident kinds.
 *
 * WHAT IS ASSERTED IS THE WIDENING. Every figure on these pages has to be the
 * area reading with the area filter widened, so the tests that matter compare
 * the organization's number against the areas' own; a second aggregate would
 * pass a "does it render" test and fail these.
 *
 * AND WHICH AREA IS A COLUMN. At this scope every row says which area it is
 * about, by the area's POSITION in the declared order and never by a colour.
 */
final class OrgIncidentsTest extends FunctionalTestCase
{
    private AreaOfInterest $north;
    private AreaOfInterest $south;

    protected function setUp(): void
    {
        parent::setUp();

        // TWO AREAS RUN THE MODULE AND ONE DOES NOT: a sum over one area is
        // the thing itself, and an area that never switched incidents on is
        // not part of this reading at all.
        $this->north = $this->anAreaWithKinds('North Range');
        $this->south = $this->anAreaWithKinds('South Range');
        $this->anAreaWithoutTheModule('Quiet Reserve');

        $this->anIncident($this->north, 'snaring', 'Snare line lifted at the forest edge', severity: IncidentSeverityEnum::Critical);
        $this->anIncident($this->north, 'livestock-depredation', 'Lion took two goats at the bomas');
        $this->anIncident($this->south, 'bushmeat', 'Two carcasses recovered on the track');
    }

    /** @param array<string, string> $query */
    private function open(string $route, array $query = []): Crawler
    {
        $this->client->loginUser($this->aManager());
        $crawler = $this->client->request('GET', $this->url($route, $query));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @param array<string, string|int> $parameters */
    private function url(string $route, array $parameters = []): string
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return $router->generate($route, $parameters);
    }

    /** THE STRIP IS TWO, and the two the provider names. */
    public function testTheStripIsTheTwoScreensTheModuleContributes(): void
    {
        $page = $this->open(IncidentOrgController::LIST_ROUTE);
        $tabs = $page->filter('.atabs a');

        self::assertSame(['Incidents', 'Incident kinds'], $tabs->each(static fn (Crawler $tab): string => trim($tab->text())));
        self::assertSame('Incidents', trim($page->filter('.atabs a.on')->text()));
        self::assertSame(
            ['incidents', 'kinds'],
            array_map(static fn (OrgPage $page): string => $page->key, new IncidentModuleProvider('operations')->orgPages()),
            'And the strip is built from the same declaration the shell mounts.',
        );
    }

    /** THE SIDEBAR ROW IS THE SHELL'S, mounted from the same declaration. */
    public function testTheSidebarCarriesTheTwoScreens(): void
    {
        $crawler = $this->open(IncidentOrgController::LIST_ROUTE);

        foreach ([IncidentOrgController::LIST_ROUTE, IncidentOrgController::KINDS_ROUTE] as $route) {
            self::assertGreaterThanOrEqual(2, $crawler->filter(\sprintf('a[href="%s"]', $this->url($route)))->count(), $route.' is a tab and a sidebar row.');
        }
    }

    /** THE MODULE LANDS ON THE REGISTER: the bar above it, the foot below, no strip of facts. */
    public function testTheModuleLandsOnTheRegister(): void
    {
        self::assertSame('/incidents', $this->url(IncidentOrgController::LIST_ROUTE));

        $this->client->loginUser($this->aManager());
        $page = $this->client->request('GET', '/incidents');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('.c form.lfilt'));
        self::assertCount(1, $page->filter('.c table.tbl + .rdf-foot'));
        self::assertCount(0, $page->filter('.factband'));

        $this->client->request('GET', '/incidents/list');
        self::assertResponseStatusCodeSame(404, 'The register answers at the module\'s own address and nowhere else.');
    }

    /** THE REGISTER IS THE AREA'S, ONE COLUMN WIDER, newest first across every area. */
    public function testTheRegisterNamesTheAreaOnEveryRow(): void
    {
        $page = $this->open(IncidentOrgController::LIST_ROUTE);
        $table = $page->filter('.c table.tbl');

        self::assertSame(
            ['id', 'area', 'category', 'what happened', 'status', 'severity', 'money', 'reported', ''],
            $table->filter('thead th')->each(static fn (Crawler $th): string => trim($th->text())),
        );

        $rows = $table->filter('tbody tr');
        self::assertCount(3, $rows);
        self::assertSame(['South Range', 'North Range', 'North Range'], $rows->each(static fn (Crawler $row): string => trim($row->filter('.orgarea')->text())));
        self::assertSame(['2', '1', '1'], $rows->each(static fn (Crawler $row): string => (string) $row->filter('.orgarea')->attr('data-cat')));
        self::assertStringContainsString($this->url('incident_show', ['uuid' => $this->uuidOf($this->south), 'reference' => 'INC-0003']), (string) $rows->eq(0)->filter('td.door a')->attr('href'));

        self::assertCount(0, $page->filter('.factband'), 'The strip is the Overview\'s.');
        self::assertStringNotContainsString('lens', mb_strtolower($page->filter('.page')->text()));
    }

    /** THE BAR IS THE HOUSE BAR — five dropdowns and the search — and the foot closes the table. */
    public function testTheRegisterCarriesTheBarAndTheFoot(): void
    {
        $page = $this->open(IncidentOrgController::LIST_ROUTE);

        $bar = $page->filter('.c form.lfilt');
        self::assertCount(1, $bar);
        self::assertSame(['area', 'category', 'status', 'month', 'department'], $bar->filter('details.i-dd')->each(static fn (Crawler $dd): string => (string) $dd->attr('data-axis')));
        self::assertCount(1, $bar->filter('.lsearch input[name="q"]'));

        $foot = $page->filter('.c table.tbl + .rdf-foot');
        self::assertCount(1, $foot);
        self::assertSame('page 1 of 1', trim($foot->filter('.rdf-page')->text()));
        self::assertStringStartsWith('3 incidents', trim($foot->text()));
    }

    /** EVERY AXIS NARROWS THE ROWS, and the area is the shell's own parameter. */
    public function testEveryAxisNarrowsTheRows(): void
    {
        $areas = $this->registerAreas(...);

        self::assertSame(['North Range', 'North Range'], $areas(['area' => $this->uuidOf($this->north)]));
        self::assertSame(['South Range', 'North Range'], $areas(['category' => 'poaching']));
        self::assertSame(['North Range'], $areas(['q' => 'goats']));
        self::assertSame([], $areas(['status' => 'closed']));

        $page = $this->open(IncidentOrgController::LIST_ROUTE);
        $option = $page->filter('details[data-axis="area"] a.i-ddopt')->reduce(static fn (Crawler $a): bool => str_contains($a->text(), 'North Range'));
        self::assertSame('2', trim($option->filter('.i-ddopt-n')->text()), 'An option carries its count.');
        self::assertStringContainsString('area='.$this->uuidOf($this->north), (string) $option->attr('href'));
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<string>
     */
    private function registerAreas(array $query): array
    {
        return $this->open(IncidentOrgController::LIST_ROUTE, $query)
            ->filter('.c table.tbl tbody .orgarea')->each(static fn (Crawler $a): string => trim($a->text()));
    }

    /** A DEPARTMENT NARROWS TO THE KINDS IT LEADS, in every area. */
    public function testADepartmentNarrowsToTheKindsItLeads(): void
    {
        $page = $this->open(IncidentOrgController::LIST_ROUTE);
        $departments = $page->filter('details[data-axis="department"] a.i-ddopt .i-ddopt-l')->each(static fn (Crawler $l): string => trim($l->text()));
        self::assertContains('All departments', $departments);
        self::assertGreaterThan(1, \count($departments));

        $first = $departments[1];
        $narrowed = $this->open(IncidentOrgController::LIST_ROUTE, ['department' => $first])->filter('.c table.tbl tbody tr .orgarea');
        self::assertGreaterThan(0, $narrowed->count());
        self::assertLessThanOrEqual(3, $narrowed->count());
    }

    /** THE KINDS ARE ONE TAXONOMY; THE TABLE SAYS WHICH AREA FILES AGAINST THE WORD. */
    public function testTheKindsTabSaysWhichAreaFilesAgainstEachWord(): void
    {
        $page = $this->open(IncidentOrgController::KINDS_ROUTE, ['kind' => 'poaching']);

        $selected = $page->filter('.kpane .krow.on');
        self::assertCount(1, $selected);
        self::assertStringContainsString('2', $selected->filter('.n')->eq(0)->text(), 'This month, over both areas.');

        $table = $page->filter('.kpane .r table.tbl');
        self::assertSame(
            ['Area', 'This month', 'Last month', 'All time', 'Sub-categories', ''],
            $table->filter('thead th')->each(static fn (Crawler $th): string => trim($th->text())),
        );
        $rows = $table->filter('tbody tr');
        self::assertSame(['North Range', 'South Range'], $rows->each(static fn (Crawler $row): string => trim($row->filter('.orgarea')->text())));
        self::assertSame(['1', '1'], $rows->each(static fn (Crawler $row): string => trim($row->filter('td')->eq(1)->text())));
        self::assertSame($this->url('incident_kinds', ['uuid' => $this->uuidOf($this->north)]), $rows->eq(0)->filter('td.door a')->attr('href'));

        self::assertCount(1, $page->filter('.kpane .r form.lfilt'));
        self::assertCount(1, $page->filter('.kpane .r table.tbl + .rdf-foot'));
        self::assertCount(0, $page->filter('.factband'));
    }

    /** A SEARCH ON THE KINDS TABLE NARROWS ITS AREAS. */
    public function testTheKindsTableSearchNarrowsItsAreas(): void
    {
        $rows = $this->open(IncidentOrgController::KINDS_ROUTE, ['kind' => 'poaching', 'q' => 'south'])->filter('.kpane .r table.tbl tbody .orgarea');

        self::assertSame(['South Range'], $rows->each(static fn (Crawler $a): string => trim($a->text())));
    }

    /** THE ORGANIZATION DASHBOARD'S CARD NOW OPENS THIS REGISTER. */
    public function testTheDashboardCardOpensTheOrganizationRegister(): void
    {
        $this->client->loginUser($this->aManager());
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter(\sprintf('[data-w="incidents"] a.more[href="%s"]', $this->url(IncidentOrgController::LIST_ROUTE))));
    }
}
