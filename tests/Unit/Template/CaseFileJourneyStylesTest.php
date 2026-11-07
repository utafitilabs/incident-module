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
 * THE JOURNEY'S ROWS WEAR THE HOUSE TABLE-ROW TINT.
 *
 * A row is a hover unit across the card's inner width, tinted as `table.tbl
 * tr:hover td` is (the accent at 4%), on a 6 px radius with no border, edge
 * to edge inside the card's 16 px sides, the moves centred in it; the
 * current row carries a stronger tint at rest and on hover, so the moves on it
 * read as its own. A text check against the sheet, not a render.
 */
final class CaseFileJourneyStylesTest extends TestCase
{
    public function testARowTakesTheHouseRowTintOnHover(): void
    {
        $sheet = self::sheet();

        self::assertMatchesRegularExpression('/\.i-jr-s\{[^}]*border-radius:6px/', $sheet);
        self::assertStringContainsString('.i-jr-s:hover{background:color-mix(in srgb,var(--acc) 4%,transparent)}', $sheet);
    }

    public function testTheCurrentRowCarriesTheStrongerTintAtRestAndOnHover(): void
    {
        self::assertStringContainsString(
            '.i-jr-s.now,.i-jr-s.now:hover{background:color-mix(in srgb,var(--acc) 6%,transparent)}',
            self::sheet(),
        );
    }

    /** The card's sides are 16 px, so the band reaches its inner edges and the line moves with the dot. */
    public function testTheBandSpansTheCardsInnerWidth(): void
    {
        $sheet = self::sheet();

        self::assertMatchesRegularExpression('/\.i-jr-s\{[^}]*padding:10px 16px;margin:0 -16px/', $sheet);
        self::assertMatchesRegularExpression('/\.i-jr-s::before\{[^}]*left:21px;top:24px;bottom:-12px/', $sheet);
        self::assertMatchesRegularExpression('/\.i-jr-acts\{[^}]*align-self:center\}/', $sheet);
    }

    public function testNoRowChangesItsTextColourOnHover(): void
    {
        self::assertDoesNotMatchRegularExpression('/\.i-jr-s[^{]*:hover[^{]*\{[^}]*(?<![-a-z])color:/', self::sheet());
    }

    private static function sheet(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/public/incidents.css');
    }
}
