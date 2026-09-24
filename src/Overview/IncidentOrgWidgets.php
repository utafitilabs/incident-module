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

namespace Uhifadhi\Incident\Overview;

use Uhifadhi\Bundle\AreaBundle\Overview\ContributesStylesheetInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\NowTile;
use Uhifadhi\Bundle\AreaBundle\Overview\OrgOverviewContributorInterface;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\Widget;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetGroup;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Incident\Model\IncidentOrgReading;
use Uhifadhi\Incident\Model\IncidentOverviewWidgets;
use Uhifadhi\Incident\Service\IncidentOrgFigures;
use Uhifadhi\Incident\UhifadhiIncidentBundle;

/**
 * WHAT THIS MODULE PUTS ON `/` — one figure in the strip and one cell under
 * it, across every area at once.
 *
 * A SECOND CONTRACT, NOT A WIDER FIRST ONE.
 * {@see IncidentOverviewContributor} answers for one AREA and is asked by
 * every area's overview; this answers for a {@see Scope} and is asked once,
 * by the organization dashboard. The two are deliberately separate classes
 * because they are separate questions — and a module with nothing to say
 * across areas would implement only the first.
 *
 * ONE READING BEHIND BOTH HALVES. The figure and the cell are derived from
 * the same {@see IncidentOrgReading}, memoised per (scope, instant), so the
 * strip can never say seven over a table drawn from a set of eight.
 *
 * THE FIGURE IS THE AREA READING ONE SCOPE WIDER. "Open incidents" on `/` is
 * the sum of what every area's own tile says, because it is literally the
 * same query with the area left out — not a second aggregate that happens to
 * agree today.
 *
 * Tagged EXPLICITLY in the bundle's extension, like every other contribution
 * point here: a reusable bundle is not autoconfigured, and without the tag
 * the module's absence from the dashboard looks exactly like a module nobody
 * installed.
 */
final readonly class IncidentOrgWidgets implements ContributesStylesheetInterface, OrgOverviewContributorInterface
{
    /** The cell's id — the one the shipped compositions name. */
    public const string CELL = 'incidents';

    /**
     * WHERE THE FIGURE SITS IN THE STRIP — third, and the strip reads left to
     * right as who is on duty, who is out, what is open and what is kept.
     *
     * THE NUMBER IS ONLY MEANINGFUL AGAINST ITS NEIGHBOURS' — the core sorts
     * the contributed tiles on it and nothing normalises the scale. The
     * roster publishes 10 and patrols 30, so this must be above 30; storage
     * publishes 40, so it must be below that. Halfway between the two leaves
     * room on either side for a module that has to come between.
     */
    private const int PRIORITY = 35;

    public function __construct(
        private IncidentOrgFigures $figures,
    ) {
    }

    /**
     * The same slug the area contributor and
     * {@see \Uhifadhi\Incident\Module\IncidentModuleProvider} declare. It is
     * what the cell's contributor tag prints, so a reader can tell whose
     * figure they are looking at and read its disappearance as the system
     * working rather than as a bug.
     */
    public function moduleSlug(): string
    {
        return IncidentOverviewWidgets::GROUP;
    }

    public function group(): WidgetGroup
    {
        return new WidgetGroup(
            IncidentOverviewWidgets::GROUP,
            'Incidents · uhifadhi/incident-module',
            'What is open across every area, and what has run past the term it was filed under.',
        );
    }

    public function widgets(): array
    {
        return [
            new Widget(self::CELL, 'Latest incidents', IncidentOverviewWidgets::GROUP, 6, [12, 9, 6], on: false,
                note: 'The open work across the organization, newest first, each row against the term its own category promised.'),
        ];
    }

    public function partialPattern(): string
    {
        return '@UhifadhiIncident/org/_w_%s.html.twig';
    }

    /**
     * THE CELL IS DRAWN IN THE HOST'S PAGE AND WEARS THIS MODULE'S SHEET.
     * Without it the category chips and the states on a contributed row
     * render as browser defaults — the failure that put blue underlined links
     * on an area overview.
     */
    public function stylesheet(): string
    {
        return UhifadhiIncidentBundle::STYLESHEET;
    }

    /**
     * ONE TILE: how much work is open anywhere, and how much of it has broken
     * a promise.
     *
     * NO TILE AT ALL where nothing has ever been filed. An installation with
     * no register has not measured nought open incidents, and a figure it did
     * not take is not a figure — the strip draws its own "nothing measured"
     * slot, which is a report rather than a claim in this module's name.
     */
    public function figures(Scope $scope, \DateTimeImmutable $now): array
    {
        $reading = $this->figures->forScope($scope, $now);
        if (!$reading->hasRegister()) {
            return [];
        }

        return [new NowTile(
            // The design's own reference for this figure. Never rendered — a
            // workshop index in shipped markup is refused by
            // tests/Unit/Template/NoWorkshopLabelsTest.
            index: 'IN·G1',
            moduleSlug: $this->moduleSlug(),
            label: 'Open incidents',
            value: (string) $reading->openCount(),
            subline: $reading->figureSubline(),
            // THE ALARM CARRIES THE COLOUR, NOT THE PLATE. The design draws
            // this tile plain with its broken promises in the failure colour:
            // a backlog is not an alarm, and colouring the whole card would
            // put the organization's largest number permanently in red.
            alarm: $reading->figureAlarm(),
            url: $reading->doorUrl,
            priority: self::PRIORITY,
        )];
    }

    /**
     * Everything this contributor's partial reads, under its own slug —
     * rendered with `with_context: false`, exactly as the contract documents.
     */
    public function context(Scope $scope, \DateTimeImmutable $now): array
    {
        return ['org' => $this->figures->forScope($scope, $now)];
    }
}
