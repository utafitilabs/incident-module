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

namespace Uhifadhi\Incident\Module;

use Uhifadhi\Contracts\ModuleProviderInterface;
use Uhifadhi\Contracts\ModuleProviderTrait;
use Uhifadhi\Contracts\Shell\OrgPage;
use Uhifadhi\Contracts\Shell\OrgPagesInterface;
use Uhifadhi\Incident\Controller\IncidentOrgController;

/**
 * Declares the one module this bundle contributes — "Incidents": what happened
 * in an area, recorded once and read by every department that needs it.
 *
 * It owns its screens ({@see entryRoute()}), so the host links straight to the
 * incidents dashboard rather than rendering the module through its generic page.
 *
 * It answers at organization level too ({@see orgPages()}): the same three
 * screens as an area's, read across every area the module is switched on in,
 * mounted by the shell beside the sidebar's other organization readings.
 */
final class IncidentModuleProvider implements ModuleProviderInterface, OrgPagesInterface
{
    use ModuleProviderTrait;

    /**
     * THE MODULE'S MACHINE IDENTITY, stated once because four readers must never
     * disagree about it: the catalogue row the registry sync upserts by it, the
     * per-area ledger an admin's Customize page writes against it, the
     * `_uhifadhi_module` default every one of this module's routes carries so the
     * parking gate knows whose page it is, and the `moduleSlug()` each overview
     * contribution returns so it disappears when an area switches the module off.
     */
    public const string SLUG = 'incidents';

    public function __construct(
        private readonly string $category,
    ) {
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    /** The one sentence the catalogue prints under the name — what the module is, for a stranger. */
    public function description(): string
    {
        return 'Everything reported — from a patrol or walked in at a gate.';
    }

    public function name(): string
    {
        return 'Incidents';
    }

    public function category(): string
    {
        return $this->category;
    }

    public function dataSource(): string
    {
        return 'Field incident reports';
    }

    public function icon(): string
    {
        return 'incident:triangle-alert';
    }

    public function entryRoute(): string
    {
        return 'incident_dashboard';
    }

    /*
     * WHAT THERE IS TO HAVE A PERMISSION ABOUT IN THIS MODULE is declared
     * through the access seam, not here — one source, four concerns, each
     * with the verbs something here actually enforces:
     * {@see \Uhifadhi\Incident\Access\IncidentConcerns}.
     */

    /**
     * THE AREA MODULE'S OWN TABS, ONE SCOPE WIDER — the same labels in the same
     * order, so a reader who has learnt the module in an area has learnt it here.
     */
    public function orgPages(): array
    {
        return [
            new OrgPage('overview', 'Overview', IncidentOrgController::OVERVIEW_ROUTE),
            new OrgPage('incidents', 'Incidents', IncidentOrgController::LIST_ROUTE),
            new OrgPage('kinds', 'Incident kinds', IncidentOrgController::KINDS_ROUTE),
        ];
    }
}
