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

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Service\RegistrySyncService;
use Uhifadhi\Bundle\ShellBundle\Widget\Entity\WidgetCustomPreset;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetDom;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetEndpoint;
use Uhifadhi\Bundle\TeamBundle\Deletion\DeletionPage;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Incident\Controller\IncidentAreaListController;
use Uhifadhi\Incident\Controller\IncidentReportController;
use Uhifadhi\Incident\Controller\IncidentSettingsController;
use Uhifadhi\Incident\Controller\IncidentTaxonomyController;
use Uhifadhi\Incident\Enum\AreaListEnum;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Service\AreaListService;
use Uhifadhi\Incident\Service\IncidentCaseService;
use Uhifadhi\Incident\Service\IncidentReportService;
use Uhifadhi\Incident\Service\TaxonomyAdminService;
use Uhifadhi\Incident\Tests\Functional\Authority\AuthorityKernel;
use Uhifadhi\Incident\Widget\IncidentWidgets;
use Uhifadhi\Test\Authority\Probe;
use Uhifadhi\Test\Authority\World;
use Uhifadhi\Test\AuthorityTableTestCase;

/**
 * THE INCIDENT MODULE'S AUTHORITY TABLE: every route this module mounts,
 * called as every kind of person, through the base the core ships.
 *
 *     UHIFADHI_RECORD_AUTHORITY_TABLE=1 vendor/bin/phpunit tests/Functional/AuthorityTableTest.php
 *
 * The module runs at Kilimani and Tambarare. Kilimani files against one kind
 * and one word, crop raiding, which carries compensation; its two incidents
 * were reported by the member. INC-0001 is verified, so money can be recorded
 * on it and it can be moved on to a response; every case-file write acts on
 * it. INC-0002 is the one the delete page is sent about.
 */
#[CoversNothing]
final class AuthorityTableTest extends AuthorityTableTestCase
{
    private const string FIRST = 'INC-0001';
    private const string SECOND = 'INC-0002';

    private string $kind = '';
    private string $subcategory = '';
    private string $entry = '';
    private string $memberPreset = '';

    protected static function getKernelClass(): string
    {
        return AuthorityKernel::class;
    }

    protected static function tableFile(): string
    {
        return __DIR__.'/authority-table.md';
    }

    protected static function scope(): string
    {
        return 'the incident module';
    }

    protected static function generator(): string
    {
        return 'tests/Functional/AuthorityTableTest.php';
    }

    protected static function controllerNamespace(): string
    {
        return 'Uhifadhi\\Incident\\Controller\\';
    }

    protected function notSavableHere(): array
    {
        return [
            'POST incident_widgets_save' => "it writes through to the sender's own preset, and nobody who sends it here is on one",
        ];
    }

    /**
     * WHAT AN INSTALLATION HAS DONE BEFORE ANYBODY OPENS AN INCIDENT: the
     * catalogue reconciled, the module switched on at both areas, Kilimani's
     * words written, two incidents filed, a word on the species list and a
     * layout of the member's own on this surface.
     */
    protected function arrange(World $world): void
    {
        $container = static::getContainer();
        $em = $this->em();

        $sync = $container->get('registry.sync');
        self::assertInstanceOf(RegistrySyncService::class, $sync);
        $sync->sync();

        $areaModules = $container->get('registry.area_modules');
        self::assertInstanceOf(AreaModuleService::class, $areaModules);
        $kilimani = $em->getRepository(AreaOfInterest::class)->findOneBy(['uuid' => $world->kilimani]);
        $tambarare = $em->getRepository(AreaOfInterest::class)->findOneBy(['uuid' => $world->tambarare]);
        self::assertInstanceOf(AreaOfInterest::class, $kilimani);
        self::assertInstanceOf(AreaOfInterest::class, $tambarare);
        $areaModules->install($kilimani, IncidentModuleProvider::SLUG);
        $areaModules->install($tambarare, IncidentModuleProvider::SLUG);

        $admin = $container->get('incident.taxonomy_admin');
        self::assertInstanceOf(TaxonomyAdminService::class, $admin);
        $kind = $admin->createKind($kilimani, 'Conflict', 'conflict');
        $subcategory = $admin->createSubcategory($kind, 'crop raiding', 'crop-raiding');
        $admin->setBlocks($subcategory, [BehaviorBlockEnum::Money], MoneyDirectionEnum::Compensation);
        $em->flush();
        $this->kind = $kind->getUuid()->toRfc4122();
        $this->subcategory = $subcategory->getUuid()->toRfc4122();

        $member = $em->getRepository(User::class)->findOneBy(['uuid' => $world->member]);
        self::assertInstanceOf(User::class, $member);

        $reports = $container->get('incident.report');
        self::assertInstanceOf(IncidentReportService::class, $reports);
        $filed = [];
        foreach (['Elephants in the maize at the river farms', 'Buffalo through the bean field'] as $title) {
            $filed[] = $reports->file(
                area: $kilimani,
                subcategory: $subcategory,
                title: $title,
                position: '{"type":"Point","coordinates":[37.1,-2.8]}',
                now: new \DateTimeImmutable('-2 days'),
                reportedBy: $member,
            );
        }

        $cases = $container->get('incident.case');
        self::assertInstanceOf(IncidentCaseService::class, $cases);
        $cases->move($filed[0], IncidentTransitionEnum::Verify, new \DateTimeImmutable('-1 day'), $member, 'N. Lekishon');

        $lists = $container->get('incident.area_lists');
        self::assertInstanceOf(AreaListService::class, $lists);
        $this->entry = $lists->add($kilimani, AreaListEnum::Species, 'Elephant')->getUuid()->toRfc4122();

        $preset = new WidgetCustomPreset(IncidentWidgets::SURFACE, $member, $kilimani->getUuid(), 'Morning');
        $em->persist($preset);
        $em->flush();
        $this->memberPreset = (string) $preset->getUuidString();
    }

    protected function probes(World $world): array
    {
        $area = ['uuid' => $world->kilimani];
        $first = $area + ['reference' => self::FIRST];
        $caseFile = 'incident_transition_'.$world->kilimani;
        $widgets = WidgetEndpoint::csrfTokenId(IncidentWidgets::SURFACE, Uuid::fromString($world->kilimani));
        $kinds = IncidentTaxonomyController::CSRF_TOKEN_ID;
        $lists = IncidentAreaListController::CSRF_TOKEN_ID;
        $kind = $area + ['kind' => $this->kind];
        $sub = $area + ['sub' => $this->subcategory];
        $entry = $area + ['entry' => $this->entry];
        $mine = $area + ['presetUuid' => $this->memberPreset];

        return [
            Probe::get('incident_dashboard', $area),
            Probe::get('incident_export', $area),
            Probe::get('incident_list', $area),
            Probe::get('incident_kinds_overview', $area),
            Probe::get('incident_show', $first),
            Probe::get('incident_new', $area),
            Probe::get('incident_kinds', $area),
            Probe::get('incident_taxonomy', $area),
            Probe::get('incident_lists', $area),
            Probe::get('incident_widgets', $area),
            Probe::get('incident_delete', $area + ['reference' => self::SECOND]),

            Probe::post('incident_create', $area, IncidentReportController::CSRF_TOKEN_ID, [
                'subcategory' => 'crop-raiding',
                'title' => 'Elephants back in the maize',
                'lat' => '-2.8',
                'lng' => '37.1',
                'blocks' => ['money' => ['claimed' => '450000']],
            ]),
            Probe::post('incident_transition', $first + ['transition' => IncidentTransitionEnum::Respond->value], $caseFile),
            Probe::post('incident_money', $first, $caseFile, ['claimed' => '450000', 'assessed' => '300000']),
            Probe::post('incident_money_waive', $first, $caseFile, ['reason' => 'The farmer withdrew the claim.']),
            Probe::post('incident_delete', $area + ['reference' => self::SECOND], DeletionPage::CSRF_ID, ['reference' => self::SECOND]),

            Probe::post('incident_settings_save', $area, IncidentSettingsController::CSRF_TOKEN_ID, ['currency' => 'KES']),

            Probe::post('incident_kinds_kind_create', $area, $kinds, ['label' => 'Mortality']),
            Probe::post('incident_kinds_kind_rename', $kind, $kinds, ['label' => 'Human–wildlife conflict']),
            Probe::post('incident_kinds_kind_deactivate', $kind, $kinds),
            Probe::post('incident_kinds_kind_reactivate', $kind, $kinds),
            Probe::post('incident_kinds_sub_create', $kind, $kinds, ['label' => 'livestock depredation']),
            Probe::post('incident_kinds_sub_rename', $sub, $kinds, ['label' => 'crop damage']),
            Probe::post('incident_kinds_sub_behaviour', $sub, $kinds, ['blocks' => ['money'], 'money_direction' => 'compensation', 'term_hours' => '48']),
            Probe::post('incident_kinds_sub_deactivate', $sub, $kinds),
            Probe::post('incident_kinds_sub_reactivate', $sub, $kinds),

            Probe::post('incident_lists_add', $area + ['list' => AreaListEnum::Species->value], $lists, ['label' => 'Buffalo']),
            Probe::post('incident_lists_rename', $entry, $lists, ['label' => 'African elephant']),
            Probe::post('incident_lists_retire', $entry, $lists),
            Probe::post('incident_lists_reactivate', $entry, $lists),

            Probe::json('incident_widgets_save', $area, $widgets, '{"order":[],"widgets":{}}', WidgetDom::CSRF_HEADER),
            Probe::post('incident_widgets_reset', $area, $widgets),
            Probe::post('incident_widgets_preset', $area + ['presetId' => 'a'], $widgets),
            Probe::post('incident_widgets_preset_copy', $area + ['presetId' => 'a'], $widgets, ['name' => 'My morning']),
            Probe::post('incident_widgets_preset_create', $area, $widgets, ['name' => 'My evening']),
            Probe::post('incident_widgets_preset_apply', $mine, $widgets),
            Probe::post('incident_widgets_preset_rename', $mine, $widgets, ['name' => 'My afternoon']),
            Probe::post('incident_widgets_preset_delete', $mine, $widgets),
        ];
    }
}
