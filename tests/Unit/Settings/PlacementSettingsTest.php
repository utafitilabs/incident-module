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

namespace Uhifadhi\Incident\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Settings\SettingDefinition;
use Uhifadhi\Contracts\Settings\SettingDepth;
use Uhifadhi\Contracts\Settings\SettingType;
use Uhifadhi\Incident\Enum\OutsideBoundaryEnum;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Settings\PlacementSettings;

/**
 * SETTINGS › MODULES › INCIDENTS › PLACEMENT: what happens to a report whose
 * point is outside its area's boundary, and how close counts as inside — the
 * organization's default, customised per area.
 */
final class PlacementSettingsTest extends TestCase
{
    public function testAReportOutsideTheBoundaryIsAcceptedWarnedOrRefusedAndWarnedByDefault(): void
    {
        $setting = $this->definition(PlacementSettings::OUTSIDE_BOUNDARY);

        self::assertSame('incidents.outside_boundary', $setting->key);
        self::assertSame(IncidentModuleProvider::SLUG, $setting->owner);
        self::assertSame('Placement', $setting->group);
        self::assertSame('A report placed outside the area’s boundary', $setting->label);
        self::assertSame(SettingType::Choice, $setting->type);
        self::assertSame(['accept', 'warn', 'refuse'], $setting->choices);
        self::assertSame(['Accept', 'Warn', 'Refuse'], array_map($setting->labelOf(...), $setting->choices));
        self::assertSame(OutsideBoundaryEnum::Warn->value, $setting->default);
        self::assertSame(SettingDepth::Area, $setting->depth);
        self::assertSame([
            'accept' => 'filed as now, marked outside the area',
            'warn' => 'filed after the person filing is told and may move it',
            'refuse' => 'the web form will not file it until the point is inside',
        ], $setting->choiceMeanings);
        self::assertSame('A report sent from a phone is always filed and marked outside the area — a refused report in the field is a lost report.', $setting->note);
    }

    public function testAPointWithinFiveHundredMetresOfTheBoundaryCountsAsInside(): void
    {
        $setting = $this->definition(PlacementSettings::INSIDE_WITHIN);

        self::assertSame('incidents.inside_within', $setting->key);
        self::assertSame('Placement', $setting->group);
        self::assertSame('Inside if within', $setting->label);
        self::assertSame(SettingType::Number, $setting->type);
        self::assertSame(500, $setting->default);
        self::assertSame('m', $setting->unit);
        self::assertSame(0, $setting->min);
        self::assertSame(SettingDepth::Area, $setting->depth);
    }

    public function testTheChoiceComesFirst(): void
    {
        self::assertSame(
            [PlacementSettings::OUTSIDE_BOUNDARY, PlacementSettings::INSIDE_WITHIN],
            array_map(static fn (SettingDefinition $d): string => $d->key, [...new PlacementSettings()->settingDefinitions()]),
        );
    }

    private function definition(string $key): SettingDefinition
    {
        foreach (new PlacementSettings()->settingDefinitions() as $definition) {
            if ($key === $definition->key) {
                return $definition;
            }
        }

        self::fail($key.' is not declared');
    }
}
