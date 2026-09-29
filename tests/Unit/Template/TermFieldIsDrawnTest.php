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
 * THE TERM, IN HOURS IS A FIELD SOMEBODY CAN SEE. It sits in a toggle row,
 * and the toggle hides ITS CHECKBOX — the styled box is the control — so a rule
 * that hid every input in the row hid the number box with it, and a term could
 * only ever be saved at its default.
 */
final class TermFieldIsDrawnTest extends TestCase
{
    public function testTheToggleHidesItsCheckboxAndNothingElse(): void
    {
        self::assertStringContainsString('.tx-tog input[type="checkbox"]{position:absolute;opacity:0;width:0;height:0}', self::stylesheet());
        self::assertDoesNotMatchRegularExpression('/\.tx-tog input\{/', self::stylesheet(), 'A bare input rule hides the term box.');
    }

    public function testTheTermBoxWearsTheModulesField(): void
    {
        self::assertStringContainsString('.tx-tog .tx-input', self::stylesheet());
    }

    public function testTheTermIsANumberFieldInAToggleRow(): void
    {
        $manager = (string) file_get_contents(\dirname(__DIR__, 3).'/templates/kinds/_manager.html.twig');

        self::assertMatchesRegularExpression('/<label class="tx-tog"[^>]*>\s*<span class="bd"><b>Term, in hours<\/b>.*?<input class="tx-input" type="number" name="term_hours"/s', $manager);
    }

    private static function stylesheet(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/public/taxonomy.css');
    }
}
