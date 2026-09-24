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
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Incident\Model\HousePalette;

/**
 * THIS MODULE DECLARES NO COLOUR.
 *
 * RULED 2026-09-21. A hue is the house's, and a module reaches one of two ways:
 * by naming a semantic token the shell defines (--acc, --ok, --warn, --fail,
 * --dim), or — for a category — by publishing the POSITION its kind holds in
 * the area's list and letting the shell's `[data-cat="n"]` rules resolve it.
 * Either way the value lives in one sheet and it is not this one.
 *
 * A literal is how that stops being true. It reads correctly in one theme, is
 * invisible in the other, and it is a second place the same decision is
 * written — so the sheets are read here for one, AND THERE ARE NO EXCEPTIONS.
 * The two that were listed — the fourth severity and the scrim under a sticky
 * bar — are tokens the shell ships, so a value here has nowhere left to hide.
 */
final class ModuleDeclaresNoColourTest extends TestCase
{
    /**
     * Rules that paint a kind's mark, and what each one is. Every one of them
     * must resolve the position rather than name a hue, because they draw the
     * SAME fact: this row, this arc, this pin and this square are one kind.
     *
     * A CHART BAND IS NOT IN THIS LIST, and that is the point: it reads the
     * same position through the atlas's own door — the series states the
     * category and the component resolves it — so there is no rule here to
     * keep in step. The donut arc and the legend square that used to be
     * listed went with the hand-drawn SVG.
     *
     * THREE OF THEM CARRY A CONTEXT. A pane that states its own `.dot`
     * background — the kinds strip, the kinds widget, the taxonomy manager —
     * out-ranks a bare `.i-hue` by specificity, so the token has to be spent
     * in the pane's own rule or the mark silently draws the pane's default.
     *
     * @var array<string, list<string>> stylesheet => the selectors in it
     */
    private const array MARK_RULES = [
        'incidents.css' => [
            '.i-cat[data-cat]',      // the register chip's border
            '.i-dot[data-cat]',      // the dot inside a filter option
            '.kx-h .dot[data-cat]',  // the kinds widget's card head
            '.krow i.dot[data-cat]', // a row of the kinds strip
        ],
        'taxonomy.css' => [
            '.tx-kind .dot[data-cat]', // a row of the manager's left pane
        ],
    ];

    public function testNeitherSheetWritesAColourValue(): void
    {
        $offenders = [];

        foreach (self::ownSheets() as $name => $css) {
            foreach (explode("\n", $css) as $number => $line) {
                if (1 === preg_match('/#[0-9A-Fa-f]{3,8}\b|\brgba?\(\s*\d|\bhsla?\(\s*\d/', $line)) {
                    $offenders[] = \sprintf('%s:%d — %s', $name, $number + 1, trim($line));
                }
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "A colour is written down in this module's own sheets:\n%s",
            implode("\n", $offenders),
        ));
    }

    /**
     * AND IT DECLARES NO PAGE-WIDE TOKEN EITHER — which is the same rule seen
     * from the other side. The five `--i-*` hues lived in a `:root` block, and
     * so did a bridge that restated a dozen of the shell's own aliases: two
     * copies of one decision, of which the sheet that loads last wins
     * everywhere, in every module that happens to be on the page.
     *
     * A token SET inside a component's own selector is not this. `--heat` on
     * the kinds overview and `--map-plate-height` on a plate are parameters
     * handed to a rule that reads them, scoped to the element that hands them
     * over; they leave the rest of the page alone.
     */
    public function testNeitherSheetDeclaresAPageWideToken(): void
    {
        $declared = [];

        foreach (self::ownSheets() as $name => $css) {
            preg_match_all('/(?<selector>[^{}]*)\{(?<body>[^}]*)\}/', $css, $rules, \PREG_SET_ORDER);

            foreach ($rules as $rule) {
                $selector = trim(preg_replace('/\/\*.*?\*\//s', '', $rule['selector']) ?? '');
                if (1 !== preg_match('/(^|,)\s*(:root|html)\b[^,]*$/', $selector)) {
                    continue;
                }

                preg_match_all('/(?<token>--[a-z0-9-]+)\s*:/i', $rule['body'], $matches);
                foreach ($matches['token'] as $token) {
                    $declared[] = $name.' '.$selector.' '.$token;
                }
            }
        }

        self::assertSame([], $declared, \sprintf(
            "These declare a page-wide design token this module does not own:\n%s",
            implode("\n", $declared),
        ));
    }

    /**
     * AND A SHEET NEVER PICKS ONE OF THE NINE. `--cat-1` spelled into a rule is
     * this module deciding which hue something wears, which is the ruling read
     * backwards: a mark takes the position the MARKUP publishes, through
     * `--cat`, and a surface that is not a category takes a semantic token
     * instead. It is how a module grew an identity colour once — the area
     * overview's contributor dots — and a module has no hue.
     *
     * The two exceptions are the in-progress status, which has no fifth
     * meaning to take and is told apart rather than judged. Both are FLAGGED
     * in the sheet and both await a ruling.
     */
    public function testNeitherSheetPicksOneOfTheNineForItself(): void
    {
        $picked = [];
        $flagged = ['.i-st.wip', '.ao-move.wip'];

        foreach (self::ownSheets() as $name => $css) {
            preg_match_all('/(?<selector>[^{};]*)\{(?<body>[^}]*)\}/', $css, $rules, \PREG_SET_ORDER);

            foreach ($rules as $rule) {
                if (1 !== preg_match('/var\(--cat-[1-9]\)/', $rule['body'])) {
                    continue;
                }

                $selector = trim(preg_replace('/\s+/', ' ', preg_replace('/\/\*.*?\*\//s', '', $rule['selector']) ?? '') ?? '');
                if (!\in_array($selector, $flagged, true)) {
                    $picked[] = $name.' — '.$selector;
                }
            }
        }

        self::assertSame([], $picked, \sprintf(
            "These pick one of the house's nine instead of resolving a published position:\n%s",
            implode("\n", $picked),
        ));
    }

    public function testEveryRuleThatPaintsAKindsMarkResolvesThePosition(): void
    {
        $sheets = self::ownSheets();
        $missing = [];

        foreach (self::MARK_RULES as $name => $selectors) {
            foreach ($selectors as $selector) {
                $declarations = self::declarationsFor($sheets[$name], $selector);

                if (null === $declarations) {
                    $missing[] = \sprintf('%s — %s is not shipped', $name, $selector);

                    continue;
                }

                if (!str_contains($declarations, 'var(--cat')) {
                    $missing[] = \sprintf('%s — %s spends no --cat: %s', $name, $selector, trim($declarations));
                }
            }
        }

        self::assertSame([], $missing, \sprintf(
            "A kind's mark is drawn without resolving the position it wears:\n%s",
            implode("\n", $missing),
        ));
    }

    /**
     * THE SEAM BETWEEN THE PHP AND THE SHEET THAT REPAINTS IT.
     *
     * {@see HousePalette} hands a host the token BY NAME, because the atlas's
     * swatch and the overview's `MapLayer` still take a colour string. The
     * shell's plate rules match that string as a presentation attribute —
     * `.viewer [fill="var(--cat-3)"]` — so a marker authored with the ordinary
     * token is repainted for imagery without this module knowing a value.
     *
     * Spell it differently on either side and nothing errors: the pin just
     * draws black over the photograph. So the two strings are compared here.
     */
    public function testTheTokenThePhpPublishesIsTheOneTheShellRepaints(): void
    {
        $shell = self::read(self::shellDirectory().'/shell.css');

        for ($position = 1; $position <= HousePalette::CATEGORIES; ++$position) {
            $token = HousePalette::token($position);

            self::assertStringContainsString(
                \sprintf('[data-cat="%d"] { --cat: %s;', $position, $token),
                $shell,
                'The shell resolves a different spelling of this position than HousePalette publishes.',
            );
            self::assertStringContainsString(
                \sprintf('.viewer [fill="%s"]', $token),
                $shell,
                'A map marker painted with this token would not be repainted for imagery.',
            );
        }
    }

    /**
     * AND THE FALLBACK IS THE SHELL'S OWN. An unindexed mark draws the muted
     * grey the shell's `[data-cat]` base rule gives it, so PHP and CSS answer
     * "no position" the same way.
     */
    public function testAnUnknownPositionFallsBackToWhatTheShellFallsBackTo(): void
    {
        $shell = self::read(self::shellDirectory().'/shell.css');

        self::assertSame('var(--fog)', HousePalette::UNKNOWN);
        self::assertStringContainsString('[data-cat] { --cat: var(--fog);', $shell);
    }

    /**
     * AND THE SEMANTIC TOKENS IT PUBLISHES ARE THE SHELL'S TOO. A state handed
     * to a host as `var(--warn)` is resolved by the same sheet that resolves a
     * position, so a legend swatch and a status chip cannot mean the same
     * thing in two colours.
     */
    public function testTheSemanticTokensItPublishesAreOnesTheShellDefines(): void
    {
        $shell = self::read(self::shellDirectory().'/shell.css');

        foreach ([HousePalette::OPEN, HousePalette::DONE] as $token) {
            $name = trim($token, 'var()');

            self::assertStringContainsString($name.': rgb(', $shell, $token.' is not a token the shell defines.');
        }
    }

    /** The declarations inside the first rule with exactly this selector. */
    private static function declarationsFor(string $css, string $selector): ?string
    {
        $pattern = '/(?:^|[}\n])\s*'.preg_quote($selector, '/').'\s*\{(?<body>[^}]*)\}/';

        return 1 === preg_match($pattern, $css, $matches) ? $matches['body'] : null;
    }

    /** @return array<string, string> file name to its text */
    private static function ownSheets(): array
    {
        $public = \dirname(__DIR__, 3).'/public';

        return [
            'incidents.css' => self::read($public.'/incidents.css'),
            'taxonomy.css' => self::read($public.'/taxonomy.css'),
        ];
    }

    private static function shellDirectory(): string
    {
        $bundle = new \ReflectionClass(ShellBundle::class)->getFileName();

        return \dirname((string) $bundle).'/public';
    }

    private static function read(string $path): string
    {
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
