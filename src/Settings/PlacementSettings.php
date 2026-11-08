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

namespace Uhifadhi\Incident\Settings;

use Uhifadhi\Contracts\Settings\SettingDefinition;
use Uhifadhi\Contracts\Settings\SettingDefinitionSourceInterface;
use Uhifadhi\Contracts\Settings\SettingDepth;
use Uhifadhi\Contracts\Settings\SettingType;
use Uhifadhi\Incident\Enum\OutsideBoundaryEnum;
use Uhifadhi\Incident\Module\IncidentModuleProvider;

/**
 * SETTINGS › MODULES › INCIDENTS › PLACEMENT: what the web form does with a
 * report whose point is outside its area's boundary, and how far beyond the
 * boundary a point still counts as inside. The organization's default,
 * customised per area. A report from a phone is filed whatever the setting
 * says, and marked outside the area.
 */
final readonly class PlacementSettings implements SettingDefinitionSourceInterface
{
    public const string OUTSIDE_BOUNDARY = 'incidents.outside_boundary';
    public const string INSIDE_WITHIN = 'incidents.inside_within';
    public const int DEFAULT_INSIDE_WITHIN_METRES = 500;

    public function settingDefinitions(): iterable
    {
        yield new SettingDefinition(self::OUTSIDE_BOUNDARY, IncidentModuleProvider::SLUG, 'Placement',
            'A report placed outside the area’s boundary', 'What happens when the point is outside the area.',
            SettingType::Choice, OutsideBoundaryEnum::Warn->value, SettingDepth::Area,
            choices: array_map(static fn (OutsideBoundaryEnum $rule): string => $rule->value, OutsideBoundaryEnum::cases()),
            position: 1,
            choiceLabels: array_combine(
                array_map(static fn (OutsideBoundaryEnum $rule): string => $rule->value, OutsideBoundaryEnum::cases()),
                array_map(static fn (OutsideBoundaryEnum $rule): string => $rule->label(), OutsideBoundaryEnum::cases()),
            ),
            choiceMeanings: array_combine(
                array_map(static fn (OutsideBoundaryEnum $rule): string => $rule->value, OutsideBoundaryEnum::cases()),
                array_map(static fn (OutsideBoundaryEnum $rule): string => $rule->means(), OutsideBoundaryEnum::cases()),
            ),
            note: 'A report sent from a phone is always filed and marked outside the area — a refused report in the field is a lost report.');
        yield new SettingDefinition(self::INSIDE_WITHIN, IncidentModuleProvider::SLUG, 'Placement',
            'Inside if within', 'A point this close to the boundary counts as inside.',
            SettingType::Number, self::DEFAULT_INSIDE_WITHIN_METRES, SettingDepth::Area,
            unit: 'm', min: 0, max: 10000, position: 2);
    }
}
