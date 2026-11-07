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
 * THE CASE FILE'S MAP PLATE IS SIZED BY THIS SCREEN'S OWN DESIGN.
 *
 * The incident case file the design draws gives the "Where" card the house
 * record plate and lets the card end where the map ends — `.pp-main>.c.plate-fill`,
 * a flex column whose viewer is `flex:none;height:min(58vh,560px)`. Sizing
 * a screen against a sibling module's screen instead of against its own design
 * is how a port drifts while every test stays green, so the rule is asserted
 * here against what the design file carries.
 *
 * A TEXT CHECK, and that is the limit of what it promises: it catches the plate
 * being sized to something other than the design, not a plate that renders
 * wrongly for some other reason. Rendered fidelity is a sweep, not a unit test.
 *
 * THE HEIGHT IS STATED ON THE PLATE ROOT, which is the only thing about a map
 * this module says. It sizes the plate, not the imagery, so it is the sum of the
 * imagery the design draws and what the plate stacks above it — each part named,
 * so the design's height is map rather than plate. The plate's column, its imagery frame, its floating legend
 * and its fullscreen are the atlas's; a module that restated any of them would
 * be the one map in the product that reads differently.
 *
 * THE CARD IS THE PLATE'S SIZE. It leads a column of cards, so it is as tall as
 * what it holds without saying anything about alignment: an `align-self` here
 * would read across the column's other axis and shrink the card off the width it
 * shares with the cards under it.
 */
final class CaseFilePlateSizingTest extends TestCase
{
    /** The height the design's own "Where" card draws its IMAGERY at: the house record plate. */
    private const string DESIGN_IMAGERY_HEIGHT = 'min(58vh,560px)';

    /** What the atlas offsets that imagery frame by inside the plate. */
    private const string ATLAS_FRAME_OFFSET = '8px';

    /**
     * `--map-plate-height` sizes the PLATE, and the plate is the imagery plus
     * everything stacked above it inside — here, the offset the atlas sets the
     * imagery frame at. So the height is that sum, each part named, and a bare
     * design height is that much plate and eight pixels less of map.
     */
    public function testThePlateIsTheSumThatLeavesTheImageryTheDesignsHeight(): void
    {
        $rule = self::plateFillRule();

        self::assertMatchesRegularExpression(
            '/--i-plate-imagery:'.preg_quote(self::DESIGN_IMAGERY_HEIGHT, '/').'/',
            $rule,
            'The case file map is a fixed plate of imagery, as incidents/detail.html draws it.',
        );
        self::assertMatchesRegularExpression(
            '/--i-plate-frame-offset:'.preg_quote(self::ATLAS_FRAME_OFFSET, '/').'/',
            $rule,
            "The atlas's own map.css offsets the imagery frame by this much.",
        );
        self::assertMatchesRegularExpression(
            '/--map-plate-height:calc\\(\\s*var\\(--i-plate-imagery\\)\\s*\\+\\s*var\\(--i-plate-frame-offset\\)\\s*\\)/',
            $rule,
            'The plate height is the sum of its named parts, so a reader sees what it is made of.',
        );
    }

    /** And the bare design number is never the plate height on its own. */
    public function testTheDesignsImageryHeightIsNotStatedAsThePlateHeight(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/--map-plate-height:'.preg_quote(self::DESIGN_IMAGERY_HEIGHT, '/').'/',
            self::plateFillRule(),
            'That sizes the plate to the imagery and the imagery to less than the design.',
        );
    }

    public function testTheCardEndsWhereTheMapEnds(): void
    {
        $sheet = self::stylesheet();

        self::assertDoesNotMatchRegularExpression(
            '/\\.c\\.plate-fill\\{[^}]*align-self/',
            $sheet,
            'Inside a column the card already ends where the map ends, and that alignment would shrink it across the column instead.',
        );
        self::assertDoesNotMatchRegularExpression(
            '/\\.c\\.plate-fill\\{[^}]*min-height/',
            $sheet,
            'A floor under a fixed height is a floor that can only fight it.',
        );
    }

    /**
     * THE PLATE'S OWN INTERNALS ARE THE ATLAS'S. A module that sizes, frames or
     * dresses the map itself is a module whose map reads differently from every
     * other one in the product.
     */
    public function testThisSheetStatesNothingAboutThePlatesInternals(): void
    {
        $sheet = self::stylesheet();

        self::assertStringNotContainsString('.viewer', $sheet);
        self::assertStringNotContainsString('leaflet', $sheet);
        self::assertStringNotContainsString('map-chrome', $sheet);
        self::assertStringNotContainsString('map-legend', $sheet);
    }

    /**
     * THE RULE IS STATED ON THE CARD, NEVER ON THE PLATE.
     *
     * A plate takes one custom property and invents no height of its own, and it
     * is the full width of whatever card it sits in. All this sheet states is
     * what that property is here, and that the card keeps to it.
     */
    public function testTheSheetStatesTheRuleOnTheCardAndNotOnThePlate(): void
    {
        $sheet = self::stylesheet();

        self::assertMatchesRegularExpression('/\\.c\\.plate-fill\\{[^}]*display:flex/', $sheet);
        self::assertMatchesRegularExpression('/\\.c\\.plate-fill\\{[^}]*flex-direction:column/', $sheet);
        self::assertDoesNotMatchRegularExpression('/\\.plate-fill \\.map-plate\\{[^}]*min-height/', $sheet);
        self::assertDoesNotMatchRegularExpression('/\\.plate-fill \\.map-plate\\{[^}]*flex:1/', $sheet);
    }

    /** The one rule this screen states about its plate. */
    private static function plateFillRule(): string
    {
        $matched = preg_match('/\\.c\\.plate-fill\\{[^}]*\\}/', self::stylesheet(), $m);
        self::assertSame(1, $matched, 'The case file states a .c.plate-fill rule.');

        return $m[0];
    }

    private static function stylesheet(): string
    {
        $sheet = file_get_contents(\dirname(__DIR__, 3).'/public/incidents.css');
        self::assertIsString($sheet);

        return $sheet;
    }
}
