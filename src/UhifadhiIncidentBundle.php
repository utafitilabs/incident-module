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

namespace Uhifadhi\Incident;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Uhifadhi\Bundle\AreaBundle\Overview\AttentionProviderInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\MapLayerProviderInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\NowTileProviderInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\OrgOverviewContributorInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\OverviewContributorInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\OverviewCopyProviderInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\PulseProviderInterface;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\ZoneRepository;
use Uhifadhi\Bundle\ShellBundle\Widget\Registry\WidgetSurfaceInterface;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetEndpoint;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetService;
use Uhifadhi\Bundle\TeamBundle\Access\Door;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Kpi\DepartmentKpiProviderInterface;
use Uhifadhi\Contracts\Kpi\StationFigureProviderInterface;
use Uhifadhi\Contracts\Kpi\ZoneFigureProviderInterface;
use Uhifadhi\Contracts\Performance\DepartmentDirectoryInterface;
use Uhifadhi\Contracts\Performance\PerformanceGeoProviderInterface;
use Uhifadhi\Contracts\Performance\PerformanceTopicProviderInterface;
use Uhifadhi\Incident\Access\IncidentConcerns;
use Uhifadhi\Incident\Access\IncidentDoors;
use Uhifadhi\Incident\Command\CloseDueCommand;
use Uhifadhi\Incident\Controller\IncidentAreaListController;
use Uhifadhi\Incident\Controller\IncidentDetailController;
use Uhifadhi\Incident\Controller\IncidentMoneyController;
use Uhifadhi\Incident\Controller\IncidentReportController;
use Uhifadhi\Incident\Controller\IncidentSettingsController;
use Uhifadhi\Incident\Controller\IncidentTaxonomyController;
use Uhifadhi\Incident\Controller\IncidentWidgetsController;
use Uhifadhi\Incident\DependencyInjection\IncidentConfiguration;
use Uhifadhi\Incident\Devkit\IncidentContentProvider;
use Uhifadhi\Incident\Module\IncidentDepartmentKpiProvider;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Module\IncidentPerformanceGeo;
use Uhifadhi\Incident\Module\IncidentPerformanceTopic;
use Uhifadhi\Incident\Module\IncidentStationFigureProvider;
use Uhifadhi\Incident\Module\IncidentZoneFigureProvider;
use Uhifadhi\Incident\Overview\IncidentAttention;
use Uhifadhi\Incident\Overview\IncidentMapLayers;
use Uhifadhi\Incident\Overview\IncidentNowTiles;
use Uhifadhi\Incident\Overview\IncidentOrgWidgets;
use Uhifadhi\Incident\Overview\IncidentOverviewContributor;
use Uhifadhi\Incident\Overview\IncidentOverviewCopy;
use Uhifadhi\Incident\Overview\IncidentPulse;
use Uhifadhi\Incident\Repository\AreaListEntryRepository;
use Uhifadhi\Incident\Repository\IncidentEventRepository;
use Uhifadhi\Incident\Repository\IncidentEvidenceRepository;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Repository\TaxonomyKindRepository;
use Uhifadhi\Incident\Repository\TaxonomySubcategoryRepository;
use Uhifadhi\Incident\Security\IncidentEvidenceVoter;
use Uhifadhi\Incident\Service\IncidentTransitionToken;
use Uhifadhi\Incident\Storage\IncidentFileSource;
use Uhifadhi\Incident\Upload\IncidentEvidenceTarget;
use Uhifadhi\Incident\Widget\IncidentWidgets;
use Uhifadhi\Storage\Model\EvidenceConstraints;
use Uhifadhi\Storage\Registry\FileSourceInterface;
use Uhifadhi\Storage\Upload\UploadTargetInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Incidents — poaching, human–wildlife conflict (with the fines and compensation
 * that follow), compliance and encroachment, and wildlife mortality: ONE record
 * type, read by Protection and Ecology alike through subsets of one taxonomy.
 *
 * Zero-config: registering the bundle maps its own entities (no host doctrine
 * block needed), registers the dashboard and reaches the host's module catalogue
 * and its department-KPI contribution point. Spatial columns ride on utafitilabs/postgis-bundle.
 *
 * AN AREA STARTS EMPTY, and that is the design. The module ships no kinds of
 * incident, seeds none and suggests none: an area writes its own in the kinds
 * editor before the first incident is filed there. Naming somebody's
 * classification scheme for them is a decision this bundle does not get to make,
 * which is why the only place the design's four kinds still exist is the devkit
 * demo content.
 */
final class UhifadhiIncidentBundle extends AbstractBundle
{
    /**
     * WHERE THIS BUNDLE'S VOCABULARY IS SERVED FROM — what AssetMapper serves
     * public/incidents.css under, stated once because it has two readers that
     * must never disagree: `templates/base.html.twig`, which links it on every
     * incidents page of the module's own, and
     * {@see IncidentOverviewContributor::stylesheet()}, which hands it to a HOST
     * that is rendering this module's plates on the area overview. The bundle's
     * name is the bundle's own knowledge, and no host should have to derive it.
     */
    public const string STYLESHEET = 'bundles/uhifadhiincident/incidents.css';

    /**
     * The taxonomy admin's own component stylesheet, served the same way and
     * linked ONLY by the taxonomy screen (see taxonomy/show.html.twig) — its
     * `tx-` vocabulary has no reader anywhere else.
     */
    public const string TAXONOMY_STYLESHEET = 'bundles/uhifadhiincident/taxonomy.css';

    /** Config lives under "incident:", not the class-derived "uhifadhi_labs_incident:". */
    protected string $extensionAlias = 'incident';

    public function configure(DefinitionConfigurator $definition): void
    {
        IncidentConfiguration::define($definition->rootNode());
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The bundle's public/ dir is auto-registered by AssetMapper under the
        // namespace `bundles/uhifadhiincident` and content-versioned — no
        // config here, no assets:install. That is where incidents.css is served
        // from; see templates/base.html.twig.

        // Ship the bundle's Stimulus controllers (assets/) under an AssetMapper
        // namespace, exactly as symfony/ux-turbo does (TurboExtension::prepend).
        // The recipe enables them in the host's assets/controllers.json.
        if ($builder->hasExtension('framework') && interface_exists(AssetMapperInterface::class)) {
            // PREPENDED, THE SHAPE EVERY symfony/ux BUNDLE WRITES. `extension()`
            // appends even when called from prependExtension(), which puts this
            // path LAST, where it overrules an installation's own framework
            // config instead of deferring to it; prepended, "any other settings
            // done explicitly inside the config/* files would override these
            // prepended settings".
            //
            // @see https://symfony.com/doc/current/bundles/prepend_extension.html
            // @see https://symfony.com/doc/current/frontend/create_ux_bundle.html
            // @see vendor/symfony/ux-map/src/UXMapBundle.php:117
            // @see vendor/symfony/ux-chartjs/src/DependencyInjection/ChartjsExtension.php:58
            $builder->prependExtensionConfig('framework', [
                'asset_mapper' => [
                    'paths' => [
                        __DIR__.'/../assets' => '@uhifadhi/incident-module',
                    ],
                ],
            ]);
        }

        /*
         * THE GLYPHS THIS MODULE DRAWS WITH, under a prefix that is its own.
         *
         * An icon set maps one prefix to ONE directory and is answered only from
         * it, so registering `incident` takes that word away from everybody else
         * in the installation — which is why a package answers for its own alias
         * and no other. The marks the shell already ships are drawn as `shell:`
         * rather than copied: an incidents page renders inside the shell, so
         * `shell:plus` there is the same mark as `shell:plus` on a core page.
         *
         * `lucide:` is not drawn from a bundle at all. That prefix belongs to the
         * installation, which may answer it with its own artwork or, with
         * on-demand fetching off, not answer it at all — and an unanswered name
         * is an empty box.
         *
         * https://symfony.com/bundles/ux-icons/current/index.html#full-configuration
         */
        if ($builder->hasExtension('ux_icons')) {
            $container->extension('ux_icons', [
                'icon_sets' => [
                    'incident' => ['path' => __DIR__.'/../assets/icons/incident'],
                ],
            ]);
        }

        // Zero-config persistence: the bundle maps its own entities, so hosts
        // never write a doctrine mappings block for incident_* tables.
        if ($builder->hasExtension('doctrine')) {
            $container->extension('doctrine', [
                'orm' => [
                    'mappings' => [
                        'UhifadhiIncident' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Entity',
                            'prefix' => 'Uhifadhi\\Incident\\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
        }

        // THE TABLES ARRIVE WITH THE CODE. The incident record, its taxonomy and
        // the area-scoped admin's own two tables are this module's, so their DDL
        // ships here too, under the module's own namespace — the shape the
        // migrations bundle documents for a bundle-shipped history:
        //
        // > migrations_paths:
        // >     'SomeBundle\Migrations': '@SomeBundle/Migrations'
        //
        // An installation runs `doctrine:migrations:migrate` and writes no
        // version for this module; `doctrine:migrations:diff` stays what it runs
        // for the entities IT owns.
        //
        // @see https://symfony.com/bundles/DoctrineMigrationsBundle/current/index.html
        // @see vendor/doctrine/doctrine-migrations-bundle/src/DependencyInjection/Configuration.php
        //      — `migrations_paths`, "A list of namespace/path pairs where to look for migrations."
        //
        // Guarded: an application may install this bundle without the migrations
        // bundle in its kernel, and there this module simply has no history to
        // run.
        if ($builder->hasExtension('doctrine_migrations')) {
            $container->extension('doctrine_migrations', [
                'migrations_paths' => [
                    'Uhifadhi\\Incident\\Migrations' => __DIR__.'/../migrations',
                ],
            ], prepend: true);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Static service wiring lives in a PHP config file (see config/services.php
        // for why PHP, not YAML). loadExtension keeps only the config-DRIVEN bits.
        $container->import('../config/services.php');

        // Explicit wiring, no autowire/autoconfigure — see config/services.php for
        // the Symfony reusable-bundle rule and its citation.
        $services = $container->services();

        // The one module this bundle contributes, collected by the host's
        // catalogue seed + module grid. The host tags every ModuleProviderInterface
        // via registerForAutoconfiguration, but that only fires for autoconfigured
        // services — and a reusable bundle doesn't autoconfigure — so the tag is
        // applied explicitly here.
        $category = \is_string($config['module_category'] ?? null) ? $config['module_category'] : 'operations';
        $services->set('incident.module_provider', IncidentModuleProvider::class)
            ->args([$category])
            ->tag('uhifadhi.module');

        /*
         * WHAT THERE IS TO HAVE A PERMISSION ABOUT HERE. Declared through the
         * same seam the core's own bundles use, so this module's four concerns
         * appear in the grants matrix beside the ground's and the directory's,
         * and vanish with the module on uninstall.
         *
         * DECLARED, NEVER GRANTED: the rows arrive, the ticks do not. Installing
         * a module must never hand an existing person a new power.
         *
         * Tagged by hand with the contract's own constant, for the same reason
         * every other tag in this file is: a reusable bundle is not
         * autoconfigured, and a module that forgot this tag would have every
         * gate refuse because the catalogue knows none of its pairs.
         */
        $services->set('incident.access.concerns', IncidentConcerns::class)
            ->tag(ConcernSourceInterface::TAG);

        /*
         * THE DOOR, ASKED FROM WHERE A TEMPLATE CANNOT ASK IT. This module's
         * own screens call `door(...)` in their own markup; a cell contributed
         * to the area overview, the organization dashboard, a department's KPI
         * strip or a performance topic is rendered by somebody else from a
         * context handed over before the render, so the question is answered
         * here instead. See the class for why it fails closed three ways.
         */
        $services->set('incident.access.doors', IncidentDoors::class)
            ->args([
                service(AreaOfInterestRepository::class),
                service(Door::class)->nullOnInvalid(),
                service('security.token_storage')->nullOnInvalid(),
            ]);

        // The deployment's own vocabulary and money unit.
        $currency = \is_string($config['currency'] ?? null) ? $config['currency'] : 'TZS';
        $builder->setParameter('incident.currency', $currency);

        /*
         * THE WRITING SCREENS AND THE WIDGET LIBRARY are registered ONLY inside
         * this guard.
         *
         * The report flow and the transition endpoint are the only routes that
         * CREATE or MOVE incidents, so they must never exist unprotected: without
         * symfony/security there is no authorization checker to enforce
         * `incidents.record` / `incidents.manage`, and a host in that state gets no
         * writing controller at all (the routes fail loudly) rather than an open
         * write endpoint. The widget library edits ONE PERSON's layout and needs a
         * signed-in user for the same reason.
         *
         * The guard asks whether SecurityBundle is actually in the kernel, read
         * from the kernel.bundles parameter. Two other checks look right and are
         * not: hasExtension('security') cannot be used here, because while an
         * extension is loading the builder is a restricted
         * MergeExtensionConfigurationContainerBuilder that does not expose other
         * extensions; and interface_exists() only proves a class is autoloadable —
         * security-core is one of this bundle's DEV dependencies, so it autoloads
         * in our own test runs even when SecurityBundle is absent, and services
         * would then reference security.* ids that do not exist. FrameworkExtension
         * reads kernel.bundles for exactly this reason.
         */
        $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : [];
        $hasSecurity = \is_array($bundles) && isset($bundles['SecurityBundle']);
        // The dashboard offers "Report incident" and the workflow buttons only
        // where those routes exist, so a host without security shows no link into
        // nowhere.
        $builder->setParameter('incident.record_screens', $hasSecurity);
        $builder->setParameter('incident.widget_screens', $hasSecurity);

        /*
         * INCIDENTS ON THE PLATFORM'S FILES HUB — unguarded, because
         * uhifadhi/storage-module is a hard requirement of this bundle.
         *
         * An incident filed by hand carries photographs: the case file's evidence
         * card is not an optional extra rendered where a Files hub happens to
         * exist, it is one of the things the record is FOR. So there is no
         * `kernel.bundles` check here and no `incident.files_hub` parameter for a
         * template to hide a door with — anything that resolves this bundle has
         * storage, and a kernel that omits the bundle fails loudly on the missing
         * `storage.*` ids rather than quietly serving a product with no evidence
         * in it.
         *
         * Tagged by hand with the interface's own constant. A reusable bundle is
         * not autoconfigured, and a module that forgot this tag would simply not
         * appear on /files — the hub grows by MODULES, so a missing source looks
         * exactly like a module nobody installed.
         */
        $services->set('incident.file_source', IncidentFileSource::class)
            ->args([service(IncidentEvidenceRepository::class), service('router')])
            ->tag(FileSourceInterface::TAG);

        // The permission half of it: without a voter claiming `incident/…` keys,
        // storage-module denies them by default and every photograph, document
        // and preview 404s on the hub.
        $services->set('incident.evidence_voter', IncidentEvidenceVoter::class)
            // OUTSIDE THE SECURITY GUARD, so the door is asked for nullably:
            // an installation with no SecurityBundle has no checker to ask, and
            // the voter then refuses — which is the contract's own
            // deny-by-default and the only safe answer about evidence.
            ->args([service(IncidentEvidenceRepository::class), service(Door::class)->nullOnInvalid()])
            ->tag('uhifadhi.evidence_access_voter');

        /*
         * THE WIDGET SURFACE, in the registry — the catalogue naming itself so
         * something can find it.
         *
         * OUTSIDE THE SECURITY GUARD BELOW, deliberately. The library screen that
         * edits a layout needs a person and therefore a firewall, but the
         * CATALOGUE is true of every installation that registered this bundle: an
         * installation with no firewall still renders the dashboard, as the
         * shipped composition, for everyone.
         *
         * And the tag is what `widget:prune` walks. A surface no service claims is
         * a surface whose stored layouts read as orphans — so registering this
         * only where security happens to be on would mean that removing a
         * firewall silently marks every arrangement anybody ever saved, in every
         * area, for deletion.
         */
        $services->set('incident.widget_surface', IncidentWidgets::class)
            ->tag(WidgetSurfaceInterface::TAG);

        if ($hasSecurity) {
            /*
             * HOW A FILE GETS ONTO A CASE FILE — this module's half of the
             * platform's one upload component, and the whole of what it wrote to
             * gain uploads.
             *
             * Under the security guard because every question it answers is
             * about a PERSON: may this one attach, may this one take a file back
             * off. Without SecurityBundle there is nobody to ask, and storage
             * registers no upload endpoint on such a host either — so a target
             * there would be an answer to a question nothing could pose.
             *
             * Tagged by hand with the contract's own constant, for the third
             * time in this file and the third same reason: a reusable bundle is
             * not autoconfigured. A module that forgot this tag gets a case file
             * whose evidence card simply has no way in.
             */
            $services->set('incident.upload_target', IncidentEvidenceTarget::class)
                ->args([
                    service(IncidentRepository::class),
                    service(IncidentEvidenceRepository::class),
                    service('incident.evidence'),
                    // THE ONE HELPER EVERY DRAWN CONTROL ASKS, and the one the
                    // platform's upload endpoint asks on this module's behalf.
                    service(Door::class),
                    service(EvidenceConstraints::class),
                ])
                ->tag(UploadTargetInterface::TAG);

            // The one token both the case file and the status board post with.
            // Registered under the security guard because without SecurityBundle
            // there is nobody to grant the permission it checks.
            $services->set('incident.transition_token', IncidentTransitionToken::class)
                ->args([
                    service(Door::class),
                    service('security.csrf.token_manager'),
                ]);

            $services->set('incident.controller.widgets', IncidentWidgetsController::class)
                ->args([
                    service('twig'),
                    service('router'),
                    service('incident.dashboard'),
                    service(TaxonomyKindRepository::class),
                    service(WidgetService::class),
                    service('incident.widget_urls'),
                    service('incident.transition_token'),
                    // The host's own endpoint service answers every widget write:
                    // this module validates no token and chooses no status code.
                    service(WidgetEndpoint::class),
                    service('security.token_storage'),
                ])
                ->public();
            $services->alias(IncidentWidgetsController::class, 'incident.controller.widgets')->public();

            $services->set('incident.controller.detail', IncidentDetailController::class)
                ->args([
                    service('twig'),
                    service('router'),
                    service(IncidentRepository::class),
                    service('incident.dashboard'),
                    service('incident.map'),
                    service('incident.case'),
                    service('incident.area_lists'),
                    service('incident.file_source'),
                    // The storage's own answer to "is there a page for a file",
                    // read from the parameter it sets for exactly that question.
                    param('storage.files.screens'),
                    // FrameworkBundle defines this id whenever symfony/security-csrf
                    // is installed, which a host running SecurityBundle already has.
                    service('security.csrf.token_manager'),
                    service('security.token_storage'),
                ])
                ->public();
            $services->alias(IncidentDetailController::class, 'incident.controller.detail')->public();

            // The money write surface's door. Registered under the same guard as
            // the transition endpoint and for the same reason: recording money
            // rides on `case-money.manage`, so without SecurityBundle there is
            // nobody to grant it and the route must not exist.
            $services->set('incident.controller.money', IncidentMoneyController::class)
                ->args([
                    service('router'),
                    service(IncidentRepository::class),
                    service('incident.money'),
                    service('security.csrf.token_manager'),
                    service('security.token_storage'),
                ])
                ->public();
            $services->alias(IncidentMoneyController::class, 'incident.controller.money')->public();

            $services->set('incident.controller.report', IncidentReportController::class)
                ->args([
                    service('twig'),
                    service('router'),
                    service('incident.report'),
                    service('incident.block_answers'),
                    service('incident.area_lists'),
                    service(TaxonomyKindRepository::class),
                    service(TaxonomySubcategoryRepository::class),
                    service('security.csrf.token_manager'),
                    service('security.token_storage'),
                ])
                ->public();
            $services->alias(IncidentReportController::class, 'incident.controller.report')->public();

            /*
             * THE AREA-SCOPED TAXONOMY ADMIN. A writing screen: every route on it
             * rides on `incident-vocabulary.configure`, so like the report flow it exists only
             * where SecurityBundle can enforce that. Its logic
             * (incident.taxonomy_admin) is unconditional; only this door is guarded.
             */
            $services->set('incident.controller.taxonomy', IncidentTaxonomyController::class)
                ->args([
                    service('twig'),
                    service('router'),
                    service('incident.taxonomy_admin'),
                    service(TaxonomyKindRepository::class),
                    service(TaxonomySubcategoryRepository::class),
                    service('security.csrf.token_manager'),
                    param('incident.record_screens'),
                ])
                ->public();
            $services->alias(IncidentTaxonomyController::class, 'incident.controller.taxonomy')->public();

            /*
             * THE LISTS EDITOR — the words this area's four gating questions
             * offer. The sibling of the kinds editor next door and guarded the
             * same way, for the same reason: every route on it rides on
             * `incident-vocabulary.configure`, and naming the words everybody else must pick
             * from is not something filing an incident earns. Its two services
             * are unconditional; only this door is guarded.
             */
            $services->set('incident.controller.area_lists', IncidentAreaListController::class)
                ->args([
                    service('twig'),
                    service('router'),
                    service('incident.area_lists'),
                    service('incident.area_list_board'),
                    service(AreaListEntryRepository::class),
                    service('security.csrf.token_manager'),
                    param('incident.record_screens'),
                ])
                ->public();
            $services->alias(IncidentAreaListController::class, 'incident.controller.area_lists')->public();

            /*
             * THE SETTINGS SECTION'S ONE POST. It changes what the area runs
             * incidents on, so it rides on `incident-vocabulary.configure` and exists only
             * where SecurityBundle can enforce it. The SERVICE behind it is
             * unconditional — reading what an area runs on is not a privilege —
             * and only this door is guarded.
             */
            $services->set('incident.controller.settings', IncidentSettingsController::class)
                ->args([
                    service('router'),
                    service('incident.settings'),
                    service('security.csrf.token_manager'),
                ])
                ->public();
            $services->alias(IncidentSettingsController::class, 'incident.controller.settings')->public();
        }

        // THE CLOCK'S HAND. `closed` is reached by time, and this is the process
        // that turns the hand — a daily cron in production. Registered in every
        // environment, because a workflow whose last step never runs is not dev
        // tooling, it is a broken workflow. See the class docblock for the cron
        // line and the scheduler note.
        $services->set('incident.command.close_due', CloseDueCommand::class)
            ->args([
                service(IncidentRepository::class),
                service('incident.case'),
            ])
            ->tag('console.command');

        /*
         * THE DEMO CONTENT, AS AN INERT DECLARATION. devkit — dev-only, installed
         * through `require-dev` — is what collects this and materialises the
         * command that runs it; in a production build nothing collects it and it
         * is an ordinary service nobody ever asks anything of. That dependency
         * graph is the firewall, which is why there is no environment check here
         * and no config flag gating it.
         *
         * THE TAG IS A LITERAL STRING, not a constant of devkit's: devkit is
         * absent in production, so a module cannot reference its classes.
         * `uhifadhi.devkit.content_provider` is the string
         * UhifadhiDevkitBundle::CONTENT_PROVIDER_TAG carries.
         */
        $services->set('incident.devkit.content', IncidentContentProvider::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service('incident.taxonomy_admin'),
                service(TaxonomyKindRepository::class),
                service(TaxonomySubcategoryRepository::class),
                service(IncidentRepository::class),
                service('incident.report'),
                service('incident.money'),
                service('incident.case'),
                service('incident.evidence'),
            ])
            ->tag('uhifadhi.devkit.content_provider');

        /*
         * The department KPI contribution point. Tagged EXPLICITLY, exactly like
         * 'uhifadhi.module' above and for the same reason: a reusable bundle is not
         * autoconfigured, so the host's autoconfiguration never fires for it.
         *
         * The slug and name are the scalars IncidentModuleProvider::slug()/name()
         * return and they must MATCH, because the host only asks this provider for
         * figures when a department attaches the module of that slug, and captions
         * the plates with that name.
         */
        /*
         * THE AREA-OVERVIEW CONTRIBUTION POINTS — five contracts, five tags, and every one of
         * them applied BY HAND for the reason spelled out above: a reusable
         * bundle is not autoconfigured, so the host's registerForAutoconfiguration
         * never fires here.
         *
         * THE TAG NAMES ARE THE INTERFACES' OWN CONSTANTS. They were literals
         * once, pinned to a copy of the interfaces kept under tests/Fixtures because
         * the real classes were an application's and off this bundle's classpath
         * at build time. They belong to AreaBundle now, which is a
         * requirement of this package, so a rename over there is a compile error
         * here rather than a module that silently stops contributing.
         *
         * 'uhifadhi.module' above stays a literal, because
         * uhifadhi/contracts publishes no constant for it.
         *
         * A missing tag looks like a module nobody installed:
         *   widget_provider  the headed section and its five widgets vanish
         *   now_tile         the two right-now plates leave the strip
         *   attention        late work stops asking for anybody
         *   map.layer        open incidents stop being drawn, legend and all
         *   pulse            the module's moves stop reaching the area's stream
         * Every one of them is covered by IncidentOverviewContributionTest.
         */
        $services->set('incident.overview.contributor', IncidentOverviewContributor::class)
            ->args([service('incident.overview.figures'), service('incident.access.doors')])
            ->tag(OverviewContributorInterface::TAG);

        /*
         * THE ORGANIZATION DASHBOARD'S SEAM — a SECOND contract beside the
         * area's, opted into deliberately. The area contributor is asked
         * against an area entity by every installed module; this is asked
         * once, against a scope, by the page at `/`. A module with nothing to
         * say across areas implements only the first and loses no cells.
         *
         * Without this tag the dashboard simply has no incidents figure and
         * no incidents cell — which looks exactly like a module nobody
         * installed, so it is covered by IncidentOrgContributionTest.
         */
        $services->set('incident.org.widgets', IncidentOrgWidgets::class)
            ->args([service('incident.org.figures')])
            ->tag(OrgOverviewContributorInterface::TAG);

        $services->set('incident.overview.now_tiles', IncidentNowTiles::class)
            ->args([service('incident.overview.figures')])
            ->tag(NowTileProviderInterface::TAG);

        $services->set('incident.overview.attention', IncidentAttention::class)
            ->args([service('incident.overview.figures'), service('router')])
            ->tag(AttentionProviderInterface::TAG);

        $services->set('incident.overview.map_layers', IncidentMapLayers::class)
            ->args([service(IncidentRepository::class)])
            ->tag(MapLayerProviderInterface::TAG);

        // THE MODULE'S WORDS INSIDE THE HOST'S SENTENCES. Not a widget and not a
        // part of one: the phrase the host drops into its own copy about the
        // operational plate, so "open incidents" is said by the module that draws
        // them rather than written into the host.
        $services->set('incident.overview.copy', IncidentOverviewCopy::class)
            ->tag(OverviewCopyProviderInterface::TAG);

        $services->set('incident.overview.pulse', IncidentPulse::class)
            ->args([service(IncidentEventRepository::class), service('router')])
            ->tag(PulseProviderInterface::TAG);

        $services->set('incident.department_kpi_provider', IncidentDepartmentKpiProvider::class)
            ->args([
                service(IncidentRepository::class),
                service('incident.access.doors'),
                'incidents',
                'Incidents',
                $currency,
            ])
            ->tag(DepartmentKpiProviderInterface::TAG);

        /*
         * THE PERFORMANCE TOPIC — this module's whole section of the
         * performance page: five figures, two charts and a matrix over the
         * departments that attach Incidents. Tagged by hand like every other
         * contribution point; without the tag the page simply has no Incidents
         * section, and nothing else changes.
         */
        $services->set('incident.performance_topic', IncidentPerformanceTopic::class)
            ->args([
                // WHO THE ROWS ARE, ASKED THROUGH THE PUBLISHED SEAM. The
                // departments and the area x module ledger live in two
                // packages this module does not depend on; the host joins them
                // once and answers in one read, so nothing here reaches across
                // a boundary to enumerate anybody.
                service(DepartmentDirectoryInterface::class),
                service(IncidentRepository::class),
                service('incident.access.doors'),
                'incidents',
                'Incidents',
            ])
            ->tag(PerformanceTopicProviderInterface::TAG);

        /*
         * THE GROUND FIGURES THAT GO WITH THAT TOPIC — filings per area, and
         * per zone of one area for the overview's "Incidents by zone" card.
         * A SEPARATE, OPTIONAL SEAM: most topics have nothing to say about
         * where, so this is its own tag rather than a method on the topic.
         * Without the tag the atlas plate simply says nobody publishes ground
         * figures, and nothing else changes.
         */
        $services->set('incident.performance_geo', IncidentPerformanceGeo::class)
            ->args([
                service(IncidentRepository::class),
                // The ground itself is the area module's: this module names it
                // by identifier and carries no geometry across the seam.
                service(AreaOfInterestRepository::class),
                service(ZoneRepository::class),
                'incidents',
            ])
            ->tag(PerformanceGeoProviderInterface::TAG);

        // THE ZONE FIGURE SEAM, tagged by hand like every other contribution
        // point. A missing tag here looks like a module nobody installed: the
        // incidents cards leave every zone surface, legend included, and
        // nothing else changes.
        $services->set('incident.zone_kpi_provider', IncidentZoneFigureProvider::class)
            ->args([
                service(IncidentRepository::class),
                'incidents',
                'Incidents',
                $currency,
            ])
            ->tag(ZoneFigureProviderInterface::TAG);

        // THE STATION FIGURE SEAM, tagged by hand for the same reason. The
        // dock draws one row per module, so a missing tag here is the
        // incidents row leaving every station dock and nothing else.
        $services->set('incident.station_kpi_provider', IncidentStationFigureProvider::class)
            ->args([
                service(IncidentRepository::class),
                'incidents',
                'Incidents',
            ])
            ->tag(StationFigureProviderInterface::TAG);
    }
}
