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

namespace Uhifadhi\Incident\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * THE ATLAS DRAWS EVERY VISUAL, AND THIS MODULE FEEDS IT.
 *
 * RULED: the atlas is the component library for every module visual — map
 * plates, charts, calendars, legends. A module states what is on a picture and
 * calls one Twig function; it computes no coordinates, ships no drawing
 * script, and cannot make its own chart, month or map look different from
 * anybody else's.
 *
 * A TEMPLATE IS WHERE THAT STOPS BEING TRUE, quietly and one line at a time. A
 * hand-rolled `<svg>` renders, so nothing fails; a `new L.map(...)` mounts, so
 * nothing fails; a `<div class="cal">` filled by a Twig loop looks right on the
 * month it was written against and wrong on the next one with six weeks in it.
 * Each of the three has already happened somewhere in this fleet, which is why
 * this reads the shipped templates as TEXT rather than trusting a review.
 *
 * ICONS ARE NOT DRAWINGS. `ux_icon()` writes an `<svg>` of its own and is the
 * platform's one way to have a glyph — so what is banned is an `<svg` this
 * module TYPED, which is exactly what a scan of the source text can tell.
 *
 * WHEN A PICTURE CANNOT BE STATED, the gap is in the atlas and it is raised
 * there. Adding a local drawing is not the workaround; it is the defect this
 * test exists to catch.
 */
final class TheAtlasDrawsEveryVisualTest extends TestCase
{
    private const string TEMPLATES = __DIR__.'/../../../templates';

    /** A drawing this module typed, rather than one a component handed it. */
    public function testNoTemplateDrawsItsOwnSvg(): void
    {
        $offenders = [];
        foreach ($this->templates() as $path => $markup) {
            if (str_contains($markup, '<svg')) {
                $offenders[] = $path;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'These draw their own SVG. A chart is `atlas_chart()`, a month is `atlas_calendar()`, a map is '
            .'`render_map()`, and a glyph is `ux_icon()` — a module states what is on a picture and never its '
            ."geometry:\n  ".implode("\n  ", $offenders),
        );
    }

    /**
     * AND NO TEMPLATE MOUNTS A MAP ITSELF. There is one Leaflet on the page and
     * the atlas's plate brings it; a module that builds a second one skips the
     * imagery, the control stack and the fullscreen rules, and the result looks
     * like a different product.
     */
    public function testNoTemplateMountsItsOwnMap(): void
    {
        $offenders = [];
        foreach ($this->templates() as $path => $markup) {
            if (preg_match('/\bL\s*\.\s*(map|tileLayer|geoJSON|marker)\b|new\s+Map\s*\(|leaflet(\.js|\.css)/i', $markup)) {
                $offenders[] = $path;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'These build a map of their own. Every plate on this surface is `render_map()`, and Leaflet arrives '
            ."with it:\n  ".implode("\n  ", $offenders),
        );
    }

    /**
     * AND NO TEMPLATE LAYS OUT A MONTH. The grid, the day head, the cell, its
     * fixed height, the day number and the "+N more" are the atlas's — which is
     * the whole reason a busy week cannot make a month taller than a quiet one.
     * A module that loops over its own days is a module that has to decide how
     * many pills fit, which it cannot know.
     */
    public function testNoTemplateBuildsItsOwnMonthGrid(): void
    {
        $offenders = [];
        foreach ($this->templates() as $path => $markup) {
            // The atlas's own cell vocabulary, typed by somebody else: the grid,
            // a day cell, a day head, a day number, a mark, the overflow.
            if (preg_match('/class="(cal|dc|dh|dn|cal-mark|cal-more|cal-nav)[ "]/', $markup)) {
                $offenders[] = $path;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "These lay out a month of their own. A month is `atlas_calendar(feed, '2026-09')`, and what fits in a "
            ."cell is the component's to decide:\n  ".implode("\n  ", $offenders),
        );
    }

    /**
     * THE POSITIVE HALF: the five readings that used to be hand-drawn SVG are
     * each stated for the atlas now. Without this, deleting a chart widget
     * would pass the three bans above by drawing nothing at all.
     */
    public function testEveryChartWidgetStatesItsChart(): void
    {
        foreach (['trend', 'severity', 'bycat', 'funnel', 'zones'] as $widget) {
            $markup = (string) file_get_contents(self::TEMPLATES.'/dashboard/_w_'.$widget.'.html.twig');

            self::assertStringContainsString(
                'atlas_chart(',
                $markup,
                $widget.' draws a chart, so it names one: the module states the series and the atlas draws it.',
            );
        }
    }

    /** AND THE MONTH IS THE COMPONENT'S TOO — IN·20 names a calendar, never a grid. */
    public function testTheCalendarWidgetNamesTheComponent(): void
    {
        $markup = (string) file_get_contents(self::TEMPLATES.'/dashboard/_w_cal.html.twig');

        self::assertStringContainsString('atlas_calendar(', $markup);
    }

    /**
     * Every Twig file this module ships, by its path from `templates/`.
     *
     * @return array<string, string>
     */
    private function templates(): array
    {
        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::TEMPLATES, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ('twig' === $file->getExtension()) {
                $found[ltrim(str_replace(self::TEMPLATES, '', $file->getPathname()), '/')] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($found);

        return $found;
    }
}
