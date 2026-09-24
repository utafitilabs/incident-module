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

namespace Uhifadhi\Incident\Widget;

use Uhifadhi\Bundle\ShellBundle\Widget\Model\Widget;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetCatalog;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetGroup;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetPreset;
use Uhifadhi\Bundle\ShellBundle\Widget\Registry\WidgetSurfaceInterface;

/**
 * THE CATALOGUE of the per-area INCIDENTS surface — a transcription of the
 * design's own surface declaration (incidents.widgets.js), which is the spec.
 *
 * THE FIVE DIRECTIONS ARE PRESETS, NOT PAGES. Incidents was explored in five
 * directions — case files, a map, a live feed, a board of counts, a board of
 * statuses — and none of them became a parallel screen. Each is a HEADED SECTION
 * of this catalogue and a PRESET that composes it, so a person adopts a
 * direction, copies it, and mixes a widget from another one into their copy. The
 * gallery that compares them lives at presets/incidents/ in the design app.
 *
 * THE COMPOSITION THE SURFACE SHIPS WITH IS NOT ONE OF THE FIVE: it takes the
 * numbers from A, the map from B, the register from A and the money board from
 * D. So it is a built-in preset in its own right, named here
 * ({@see DEFAULT_LABEL}) rather than left as a generic "Default layout" — the
 * host's {@see WidgetCatalog::builtins()} leads the strip with it.
 *
 * It rides the shell's widget machinery rather than a copy of it: the dashboard, the
 * library and the save endpoint all read this one object, so a widget can never
 * exist on one screen and not the other.
 *
 * AREA-SCOPED: the same person may lay one area's incidents out one way and
 * another area's another, so every widget-framework call passes the area's UUID
 * and the stored preference rows are keyed by (surface, user, area).
 *
 * A CATALOGUE IS A STATEMENT OF WHAT A SURFACE SHIPS, so this class has no
 * dependencies and nothing may vary it at runtime. It is nonetheless a
 * {@see WidgetSurfaceInterface} and TAGGED as one (see the bundle's
 * loadExtension), because being FINDABLE is the half a catalogue alone cannot
 * do: `widget:prune` walks the registry, and layouts keyed to a surface no
 * service claims are exactly what it deletes — here that would be every
 * arrangement anybody ever saved, in every area. The static accessors stay:
 * they are how this module's own controllers and templates reach the catalogue
 * without a container round-trip.
 */
final class IncidentWidgets implements WidgetSurfaceInterface
{
    /** What a stored preference row is keyed by. */
    public const string SURFACE = 'incidents';

    /** What the composition this module ships with is CALLED when it leads the preset strip. */
    public const string DEFAULT_LABEL = 'The incidents dashboard';

    public const string DEFAULT_DESCRIPTION = 'What the module ships with: the counts, then where, then what, then the money. The direction-neutral screen — adopt one of the five below to lead with something sharper.';

    public function catalog(): WidgetCatalog
    {
        return self::declaration();
    }

    /** The catalogue, reachable without an instance — see the class docblock. */
    public static function declaration(): WidgetCatalog
    {
        $groups = [];
        $presets = [];
        foreach (self::directions() as $letter => [$label, $tradeOff, $layout]) {
            $groups[] = new WidgetGroup($letter, $label, $tradeOff);
            // The preset id IS the direction's letter, exactly as the design
            // declares it, and the trade-off line is written ONCE — so a headed
            // section and the preset that adopts it can never disagree about what
            // the same design costs.
            $presets[] = new WidgetPreset($letter, $label, $tradeOff, $layout);
        }

        return new WidgetCatalog(
            self::SURFACE,
            $groups,
            self::widgets(),
            $presets,
            // A person who has never chosen opens on the shipped composition, not
            // on the first direction: the module's own screen is direction-neutral
            // on purpose, and picking one of the five for somebody would be making
            // the choice the gallery exists to let them make.
            WidgetCatalog::DEFAULT_PRESET_ID,
            self::DEFAULT_LABEL,
            self::DEFAULT_DESCRIPTION,
        );
    }

    /**
     * The surface's widgets, in the order the shipped composition lays them out.
     * `cols` is the width the catalogue draws it at, the spans are the widths the
     * width-chips offer (widest first, as the host's Widget enforces), and `on`
     * is whether the SHIPPED composition includes it.
     *
     * @return list<Widget>
     */
    private static function widgets(): array
    {
        return [
            new Widget('kpis', 'KPI strip', 'a', 12, [12, 9, 6, 3], on: true, note: 'Open incidents, this month\'s filings, money outstanding and time-to-verify.'),
            // MAPS LEAD, just below the KPIs: the shipped composition answers
            // "where" before "what", so the map sits second in declaration order
            // even though it is filed under Map first (group 'b') in the library.
            // Listed here rather than with its group-'b' siblings BECAUSE
            // declaration order IS the shipped composition's order — the counts,
            // then where, then what, then the money ({@see DEFAULT_DESCRIPTION}).
            new Widget('map', 'Incident map', 'b', 12, [12, 9, 6], on: true, note: 'Where every incident was filed; hue is the category, hollow means closed.'),
            new Widget('register', 'Incidents', 'a', 12, [12, 9, 6], on: true, note: 'Every incident as a row: id, category, what happened, zone, status, severity, money.'),
            // THE READ-ONLY VOCABULARY CARD. It shows what this area files and how
            // often each kind was filed this month; editing is one click away in
            // Configure, and never here.
            new Widget('kinds', 'Incident kinds', 'a', 12, [12, 9, 6], on: true, note: 'The kinds this area files, with this month\'s count under each and a mark on the sub-categories that carry money. Read only — editing is a click away in Configure.'),
            // THE MONTH, drawn by the atlas's calendar component. Filed under
            // Map first because it answers WHEN the way the plate answers
            // WHERE, and on in the shipped composition: a month is how a
            // supervisor sees a run of bad nights that a list of rows hides.
            new Widget('cal', 'Incidents calendar', 'b', 12, [12, 9, 6], on: true, note: 'The month as a calendar, one mark per incident, filled while it is open.'),
            new Widget('queue', 'My queue', 'a', 12, [12, 9, 6], on: false, note: 'Only what is waiting on you, oldest first, with the clock against each one.'),
            // THE WAY IN TO THE REPORT FLOW, filed under Case files: that is the
            // direction of whoever keeps the register, and opening a new file is
            // the file drawer's own verb.
            //
            // OFF, and in no design the surface ships. The dashboard header
            // already carries a Report control, so this card is for somebody who
            // files often enough to want the discipline and the last filing in
            // their composition — a second door nobody asked for would just be
            // two of the same button.
            //
            // It opens the FULL PAGE rather than the drawer: a dashboard is
            // standalone context. See the partial for why that follows from the
            // container ruling rather than from a preference.
            new Widget('report', 'File an incident', 'a', 6, [12, 9, 6], on: false, note: 'What a report cannot be without, and the button that opens the filing page.'),
            new Widget('maplist', 'Map + results', 'b', 12, [12, 9], on: false, note: 'The map at full height with the matching incidents docked beside it.'),
            new Widget('zones', 'By zone', 'b', 6, [12, 9, 6, 3], on: false, note: 'Incidents by the zone they fall in, this month.'),
            // THE TWO-BY-TWO RAIL OF GRAPHS the design added under the map: when
            // it happened, what kind, how serious — the three dimensions a map pin
            // cannot draw, beside the zone chart they share the grid with. All OFF:
            // the shipped composition leads with the map, not a rail of graphs.
            new Widget('trend', 'Incidents over time', 'b', 6, [12, 9, 6], on: false, note: 'Six months of filings — the line the map cannot draw. Answers "is it getting worse".'),
            new Widget('bycat', 'By category', 'b', 6, [12, 9, 6], on: false, note: 'The month\'s mix by kind of incident — the same four hues the map paints, read as a share of the whole.'),
            new Widget('severity', 'By severity', 'b', 6, [12, 9, 6], on: false, note: 'How serious this month\'s incidents are — the dimension a map pin cannot show.'),
            new Widget('spark', 'Thirty-day load', 'c', 12, [12, 9, 6, 3], on: false, note: 'One bar per day — how much came in, and when it spiked.'),
            new Widget('feed', 'Incident feed', 'c', 12, [12, 9, 6], on: false, note: 'Newest first, grouped by day, with each day\'s own count.'),
            new Widget('evidence', 'Latest evidence', 'c', 12, [12, 9, 6], on: false, note: 'The most recent photographs and documents attached to any incident.'),
            new Widget('categories', 'What the categories are', 'd', 3, [12, 9, 6, 3], on: false, note: 'The reference card: all four kinds of incident spelled out, with the sixteen sub-categories under each and which department leads it. The one place the list is written out in full.'),
            new Widget('matrix', 'Counts by category and status', 'd', 12, [12, 9], on: false, note: 'How many of each kind of incident, and how far each has got. Every number is a link into the list behind it.'),
            new Widget('money', 'Fines & compensation', 'd', 12, [12, 9, 6], on: true, note: 'Money owed to the authority and money owed by it, and how much has actually moved.'),
            new Widget('board', 'Status board', 'e', 12, [12], on: false, note: 'Five columns, one per state; dragging a card moves the incident on.'),
            new Widget('sla', 'Ageing & breaches', 'e', 6, [12, 9, 6], on: false, note: 'Incidents past the term their category promises, oldest first.'),
            new Widget('funnel', 'Status funnel', 'e', 6, [12, 9, 6, 3], on: false, note: 'How many of this month\'s incidents reached each state.'),
            new Widget('rail', 'Where things stand', 'e', 12, [12, 9, 6], on: false, note: 'The state machine for the ONE incident you last touched: what it passed, where it is, what is left, and only the transitions that are legal from here.'),
        ];
    }

    /**
     * THE FIVE DIRECTIONS: the letter the library files each under, what it is
     * called, what the gallery says it COSTS, and the layout that IS that design —
     * listed is on, at the width listed, in that order; absent is off.
     *
     * The trade-off line is the gallery's own sentence, verbatim. It is written
     * once here and read twice — by the headed section and by the preset — so the
     * product can never say something about a direction that the design did not.
     *
     * @return array<string, array{string, string, array<string, int>}>
     */
    private static function directions(): array
    {
        return [
            'a' => [
                'Case files',
                'Every incident is a record with a number and a due date; the dashboard is the file drawer and the list of what is yours today. Fastest for whoever keeps the list, and the only direction that never hides a field; says nothing about where anything happened.',
                ['kpis' => 12, 'register' => 12, 'queue' => 12],
            ],
            'b' => [
                'Map first',
                'The map is the dashboard and the results dock beside it, so "where" is answered before "what"; below it a two-column rail of graphs answers when, what mix, how serious and which zones. Unbeatable for spotting a cluster or a hotspot forming; weakest for money, paperwork and anything without good coordinates.',
                ['kpis' => 12, 'maplist' => 12, 'trend' => 6, 'bycat' => 6, 'severity' => 6, 'zones' => 6],
            ],
            'c' => [
                'Live feed',
                'A reverse-chronological feed grouped by day, above a thirty-day load bar. Reads like a radio log and is the best direction for a duty officer on shift; older-than-a-week work sinks out of sight.',
                ['spark' => 12, 'feed' => 12, 'evidence' => 12],
            ],
            'd' => [
                'Category board',
                'Counts first, by kind of incident against how far each one has got, and every number is a link into the list behind it. The clearest view of what this area is actually dealing with, and the one that makes the Protection/Ecology split obvious; needs a second click before you see a single incident.',
                ['kpis' => 12, 'matrix' => 12, 'money' => 12],
            ],
            'e' => [
                'Status board',
                'Five columns, one per state, and dragging a card moves the incident on. Turns the list into a queue a supervisor can clear and makes it obvious what has been sitting too long; a busy month does not fit on a screen.',
                ['kpis' => 12, 'rail' => 12, 'board' => 12, 'sla' => 6, 'funnel' => 6],
            ],
        ];
    }
}
