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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\ZoneRepository;
use Uhifadhi\Bundle\AtlasBundle\Map\MapBuilderInterface;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetService;
use Uhifadhi\Contracts\Shell\ConfigurationSectionsInterface;
use Uhifadhi\Contracts\Shell\ModuleTabsInterface;
use Uhifadhi\Incident\Controller\IncidentController;
use Uhifadhi\Incident\Controller\IncidentKindsOverviewController;
use Uhifadhi\Incident\Controller\IncidentListController;
use Uhifadhi\Incident\Repository\AreaListEntryRepository;
use Uhifadhi\Incident\Repository\IncidentEventRepository;
use Uhifadhi\Incident\Repository\IncidentEvidenceRepository;
use Uhifadhi\Incident\Repository\IncidentLinkRepository;
use Uhifadhi\Incident\Repository\IncidentMoneyRepository;
use Uhifadhi\Incident\Repository\IncidentPartyRepository;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Repository\IncidentSettingsRepository;
use Uhifadhi\Incident\Repository\IncidentZoneLocator;
use Uhifadhi\Incident\Repository\TaxonomyKindRepository;
use Uhifadhi\Incident\Repository\TaxonomySubcategoryRepository;
use Uhifadhi\Incident\Service\AreaListBoardService;
use Uhifadhi\Incident\Service\AreaListService;
use Uhifadhi\Incident\Service\IncidentBlockAnswerService;
use Uhifadhi\Incident\Service\IncidentCaseService;
use Uhifadhi\Incident\Service\IncidentDashboardService;
use Uhifadhi\Incident\Service\IncidentEvidenceService;
use Uhifadhi\Incident\Service\IncidentKindsOverviewService;
use Uhifadhi\Incident\Service\IncidentListService;
use Uhifadhi\Incident\Service\IncidentMapService;
use Uhifadhi\Incident\Service\IncidentMoneyService;
use Uhifadhi\Incident\Service\IncidentOrgFigures;
use Uhifadhi\Incident\Service\IncidentOverviewFigures;
use Uhifadhi\Incident\Service\IncidentReportService;
use Uhifadhi\Incident\Service\IncidentSettingsService;
use Uhifadhi\Incident\Service\IncidentTransitionService;
use Uhifadhi\Incident\Service\IncidentWidgetUrls;
use Uhifadhi\Incident\Service\TaxonomyAdminService;
use Uhifadhi\Incident\Shell\IncidentConfigurationSections;
use Uhifadhi\Incident\Shell\IncidentModuleTabs;
use Uhifadhi\Incident\Twig\IncidentTrailExtension;

/*
 * The bundle's static service wiring.
 *
 * PHP (not YAML) on purpose: a reusable bundle must not force symfony/yaml onto
 * hosts, and FQCN references stay refactor-safe and phpstan-checked. Imported by
 * UhifadhiIncidentBundle::loadExtension(), which keeps only the config-DRIVEN
 * definitions (module category, currency, dev tooling).
 *
 * Everything below is defined EXPLICITLY — no autowire(), no autoconfigure(), and
 * ids prefixed with the bundle alias — because this bundle is installed by other
 * projects via Composer, which is what Symfony calls a reusable bundle:
 *
 *   "Services should not use autowiring or autoconfiguration. Instead, all
 *    services should be defined explicitly."
 *   "If the bundle defines services, they must be prefixed with the bundle alias."
 *   — https://symfony.com/doc/current/bundles/best_practices.html
 *
 * Controllers extend nothing and take their collaborators explicitly, patterned
 * on FrameworkBundle's own TemplateController (see
 * vendor/symfony/framework-bundle/Controller/TemplateController.php).
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    /*
     * THE STATE MACHINE. No entity manager, deliberately: it mutates the object
     * graph and the caller flushes, which is what keeps the whole workflow
     * unit-testable without a database.
     */
    $services->set('incident.transitions', IncidentTransitionService::class);

    // "Which zone is this point in?" — asked once, when an incident is filed.
    $services->set('incident.zone_locator', IncidentZoneLocator::class)
        ->args([service('doctrine.orm.entity_manager')]);

    /*
     * WHERE EVERY INCIDENT WAS FILED, stated in PHP for the atlas to draw. The
     * module ships no map JavaScript: it says what is on the plate and
     * render_map() puts it on the page.
     */
    $services->set('incident.map', IncidentMapService::class)
        ->args([
            service(MapBuilderInterface::class),
            // The area's zones are the AREA's, read from the bundle that owns
            // them rather than copied into this module's schema.
            service(ZoneRepository::class),
            // Where a mark leads when it is clicked. The url is generated here
            // and travels as a feature property; the atlas writes the link.
            service('router'),
        ]);

    $services->set('incident.dashboard', IncidentDashboardService::class)
        ->args([
            service(IncidentRepository::class),
            service(TaxonomyKindRepository::class),
            service('incident.transitions'),
            service('incident.map'),
            service('incident.file_source'),
            param('incident.currency'),
        ]);

    /*
     * THE MODULE'S READING OF ONE AREA'S MORNING — what it contributes to the
     * HOST's area overview: four cards, two right-now tiles, its attention items,
     * its map layer. All five ask this one service, and it memoises per (area,
     * instant), so a page that draws several of them measures the register once.
     */
    $services->set('incident.overview.figures', IncidentOverviewFigures::class)
        ->args([
            service(IncidentRepository::class),
            service(TaxonomyKindRepository::class),
            service('router'),
            param('incident.currency'),
        ]);

    /*
     * THE MODULE'S READING OF A WHOLE ORGANIZATION — what it contributes to
     * the ORGANIZATION DASHBOARD at `/`: the "Open incidents" figure in the
     * four-to-a-row strip and the cell under it. Both ask this one service,
     * and it memoises per (scope, instant), so the two halves of one
     * contribution can never be measured a second apart.
     *
     * IT IS THE AREA READING ONE SCOPE WIDER, not a second aggregate: the
     * rows come from the repository's own scope-aware open-work query with
     * the area left out.
     */
    $services->set('incident.org.figures', IncidentOrgFigures::class)
        ->args([
            service(IncidentRepository::class),
            service('router'),
        ]);

    /*
     * THE CASE FILE'S WRITE SURFACE — what happens to an incident after it is
     * filed, and the place a workflow decision becomes durable. It exists so a
     * controller never holds an entity manager for a single flush: the state
     * machine decides, this writes, the screen authorizes and responds.
     */
    $services->set('incident.case', IncidentCaseService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('incident.transitions'),
            service(IncidentLinkRepository::class),
        ]);

    /*
     * WHAT STEP 2'S BLOCKS ASKED, READ AND GATED. Pure logic with nothing behind
     * it — no database, no request — so the form, the endpoint and a test all ask
     * the same object the same question and cannot disagree about what holds up a
     * filing.
     */
    $services->set('incident.block_answers', IncidentBlockAnswerService::class);

    $services->set('incident.report', IncidentReportService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(IncidentRepository::class),
            service('incident.zone_locator'),
        ]);

    /*
     * THE MONEY WRITE SURFACE — the four amounts and the waiver, the row born
     * lazily on the first save. Unconditional like the report service: it is pure
     * domain logic (create the row, apply the amounts, leave a timeline event) and
     * the CONTROLLER that fronts it is what the security guard registers, because
     * recording money rides on `case-money.manage`.
     */
    $services->set('incident.money', IncidentMoneyService::class)
        ->args([service('doctrine.orm.entity_manager')]);

    /*
     * THE EVIDENCE WRITE SURFACE — the one door a photograph or a document takes
     * onto a case file, whether a browser or an importer sent the bytes.
     *
     * Unguarded, like the file source and the voter: uhifadhi/storage-module is a
     * hard requirement of this bundle, so `storage.evidence_storage` is always
     * there. Separate from incident.case deliberately — this is the only write on
     * a case file that leaves the database, and its refusals are the deployment's
     * rules about files rather than the workflow's about records.
     */
    $services->set('incident.evidence', IncidentEvidenceService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('storage.evidence_storage'),
            service(IncidentEvidenceRepository::class),
        ]);

    /*
     * THE AREA-SCOPED TAXONOMY ADMIN's logic. Registered unconditionally — it is
     * pure domain logic (create/rename/retire kinds and sub-categories, compose
     * behaviour blocks, keep wire-codes unique per area) with no security of its
     * own; the CONTROLLER that fronts it is registered only under the security
     * guard (see UhifadhiIncidentBundle), because the write rides on
     * "incidents.manage" and there is nobody to grant it without a firewall.
     */
    $services->set('incident.taxonomy_admin', TaxonomyAdminService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(TaxonomyKindRepository::class),
            service(TaxonomySubcategoryRepository::class),
        ]);

    /*
     * THE FOUR PER-AREA LISTS. Two services, because they answer two different
     * questions and only one of them writes: AreaListService is every write that
     * reaches a word plus the words the report form and the case file read, and
     * AreaListBoardService assembles the editor's four folds — its rows, its
     * counts and the "used by" line derived from the block catalogue.
     *
     * Both unconditional. They are pure domain logic with no security of their
     * own; the CONTROLLER that fronts them is registered only under the security
     * guard (see UhifadhiIncidentBundle), because every write rides on
     * "incidents.manage" and there is nobody to grant it without a firewall —
     * and the READ is needed on the report form, which the guard also covers, and
     * on the case file, which everybody can open.
     */
    $services->set('incident.area_lists', AreaListService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(AreaListEntryRepository::class),
        ]);

    $services->set('incident.area_list_board', AreaListBoardService::class)
        ->args([
            service(AreaListEntryRepository::class),
            service(TaxonomyKindRepository::class),
        ]);

    /*
     * Repositories keep FQCN ids — the one place the bundle-alias prefix cannot
     * be used: ServiceRepositoryCompilerPass keys its locator by SERVICE ID over
     * findTaggedServiceIds(), while ContainerRepositoryFactory looks a repository
     * up by CLASS NAME; tagged-id lookup never sees aliases.
     *
     * @see vendor/doctrine/doctrine-bundle/src/DependencyInjection/Compiler/ServiceRepositoryCompilerPass.php
     */
    foreach ([
        IncidentRepository::class,
        IncidentEventRepository::class,
        IncidentEvidenceRepository::class,
        IncidentPartyRepository::class,
        IncidentMoneyRepository::class,
        IncidentLinkRepository::class,
        TaxonomyKindRepository::class,
        TaxonomySubcategoryRepository::class,
        AreaListEntryRepository::class,
    ] as $repository) {
        $services->set($repository)
            ->args([service('doctrine')])
            ->tag('doctrine.repository_service');
    }

    /*
     * THE CRUMB'S ONE HELPER — `incident_url()`, which answers null for a screen
     * the installation did not mount instead of throwing the page away. See
     * IncidentTrailExtension for why a module's breadcrumb cannot use path().
     */
    $services->set('incident.twig.trail', IncidentTrailExtension::class)
        ->args([service('router')])
        ->tag('twig.extension');

    /*
     * The dashboard. Routes reference "IncidentController::dashboard" and
     * Symfony's controller resolver asks the container for that class name, so it
     * gets the alias the best practices prescribe: "For public services, aliases
     * should be created from the interface/class to the service id."
     *
     * The writing screens (the report flow, the transition endpoint) and the
     * widget library are registered in the bundle's SecurityBundle guard instead —
     * see UhifadhiIncidentBundle::loadExtension().
     */
    // The library's URL map, shared by the dashboard and the library itself.
    $services->set('incident.widget_urls', IncidentWidgetUrls::class)
        ->args([service('router')]);

    $services->set('incident.controller.dashboard', IncidentController::class)
        ->args([
            service('twig'),
            service('incident.dashboard'),
            service(TaxonomyKindRepository::class),
            // The HOST's widget framework, by its own service id: the module
            // ships a catalogue, never a copy of the algebra that resolves it.
            service(WidgetService::class),
            service('incident.widget_urls'),
            param('incident.record_screens'),
            param('incident.widget_screens'),
            // Null where the host runs no security: nobody is signed in, so the
            // dashboard renders the module's shipped composition for everyone.
            service('security.token_storage')->nullOnInvalid(),
            // Registered only under the bundle's SecurityBundle guard, so it is
            // genuinely absent in an installation without security — and the
            // board then renders as a board of links.
            service('incident.transition_token')->nullOnInvalid(),
        ])
        ->public();

    $services->alias(IncidentController::class, 'incident.controller.dashboard')->public();

    /*
     * WHICH SLICE OF THE FILTERED ANSWER IS ON SCREEN. Pure — it takes the
     * answer the counts are already worked out from, so the caption's total and
     * the rows under it are two readings of one thing.
     */
    $services->set('incident.list', IncidentListService::class);

    $services->set('incident.controller.list', IncidentListController::class)
        ->args([
            service('twig'),
            service('incident.dashboard'),
            service(TaxonomyKindRepository::class),
            service('incident.list'),
            param('incident.record_screens'),
            service('security.token_storage')->nullOnInvalid(),
        ])
        ->public();

    $services->alias(IncidentListController::class, 'incident.controller.list')->public();

    /*
     * THE AREA'S OWN VOCABULARY, WITH WHAT HAS BEEN FILED AGAINST EACH WORD.
     * Both sides are the area's own model, so a word's count is the incidents
     * filed against that very row.
     */
    $services->set('incident.kinds_overview', IncidentKindsOverviewService::class)
        ->args([
            service(TaxonomyKindRepository::class),
            service(IncidentRepository::class),
        ]);

    $services->set('incident.controller.kinds_overview', IncidentKindsOverviewController::class)
        ->args([
            service('twig'),
            service('incident.kinds_overview'),
        ])
        ->public();

    $services->alias(IncidentKindsOverviewController::class, 'incident.controller.kinds_overview')->public();

    /*
     * THE MODULE'S DATA PLACES. Tagged BY HAND: a reusable bundle does not
     * autoconfigure, so the platform's registerForAutoconfiguration never fires
     * for it, and a forgotten tag is a module with no strip and no children in
     * the sidebar's tree, with nothing anywhere saying why.
     */
    $services->set('incident.module_tabs', IncidentModuleTabs::class)
        ->tag(ModuleTabsInterface::TAG);

    // What one area runs incidents on. Registered with the rest for the same
    // reason: a repository is a query surface over a mapped entity.
    $services->set(IncidentSettingsRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    /*
     * WHAT ONE AREA COUNTS MONEY IN. Unconditional: reading it is not a
     * privilege, and the WRITE rides on a guarded controller.
     */
    $services->set('incident.settings', IncidentSettingsService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(IncidentSettingsRepository::class),
            param('incident.currency'),
        ]);

    /*
     * WHAT IS ON THE MODULE'S ONE CONFIGURE PAGE. Tagged by hand, like the tabs
     * and for the same reason; a module with no declaration has no Configure
     * action at all.
     */
    $services->set('incident.configuration_sections', IncidentConfigurationSections::class)
        ->args([
            service('request_stack'),
            service(AreaOfInterestRepository::class),
            service('incident.settings'),
            service(TaxonomyKindRepository::class),
            service('security.csrf.token_manager')->nullOnInvalid(),
        ])
        ->tag(ConfigurationSectionsInterface::TAG);
};
