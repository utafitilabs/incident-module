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

namespace Uhifadhi\Incident\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\ShellBundle\Service\TablePageService;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Incident\Model\IncidentFilter;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Repository\TaxonomyKindRepository;
use Uhifadhi\Incident\Service\IncidentDashboardService;

/**
 * EVERY INCIDENT FILED IN THIS AREA — the dashboard's list widget without a cap
 * on it, which is the one thing a widget cannot be: a card that grew with the
 * data would make a busy month a page nobody can read.
 *
 * IT IS A DATA PLACE, so it is one of the module's two tabs, and the case file
 * lights it — see {@see \Uhifadhi\Incident\Shell\IncidentModuleTabs}.
 *
 * ONE FILTER, ONE READING. It reads the request through the SAME
 * {@see IncidentFilter} the dashboard and the export read, and it pages the SAME
 * answer the counts are worked out from — so the number in the caption and the
 * rows under it can never be describing different questions.
 *
 * LEAN, and without a base class: a reusable bundle's controller takes what it
 * needs in its constructor and is wired explicitly, exactly as FrameworkBundle's
 * own TemplateController is.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html
 * @see vendor/symfony/framework-bundle/Controller/TemplateController.php
 */
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => IncidentModuleProvider::SLUG])]
final readonly class IncidentListController
{
    public function __construct(
        private Environment $twig,
        private IncidentDashboardService $dashboard,
        private TaxonomyKindRepository $kinds,
        private TablePageService $pages,
        /**
         * Whether the writing screens EXIST in this installation — they need
         * SecurityBundle. Whether THIS person may file is the other half, asked
         * in the template with `door('incidents.record', area)`.
         */
        private bool $recordScreens = false,
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    /**
     * Ahead of the case file's `/incidents/{reference}` as belt and braces: the
     * reference pattern could not match the literal segment anyway, and an
     * ordering that depends on that is an ordering nobody can see.
     */
    #[Route(
        '/areas/{uuid}/modules/incidents/incidents',
        name: 'incident_list',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET'],
        priority: 2,
    )]
    #[IsGranted('incidents.read', subject: 'area')]
    public function list(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $now = new \DateTimeImmutable();
        $viewer = $this->viewer();
        $filter = IncidentFilter::fromRequest($request, $area, $this->kinds->forArea($area), ...IncidentController::windowFor($request, $now));

        $dashboard = $this->dashboard->build($filter, $now, $viewer);
        // ONE PAGE OF THE ANSWER THE COUNTS ARE WORKED OUT FROM, so the caption's
        // total and the rows under it are two readings of one thing. `?page`
        // and `?per` are untrusted: the pager falls back rather than failing.
        $table = $this->pages->read($request, \count($dashboard->recent));

        return new Response($this->twig->render('@UhifadhiIncident/list/show.html.twig', [
            'area' => $area,
            'now' => $now,
            'filter' => $filter,
            // The filter row's chips read their counts off the dashboard, which
            // is the one place they are worked out.
            'dashboard' => $dashboard,
            'recordScreens' => $this->recordScreens,
            'rows' => $table->slice($dashboard->recent),
            'table' => $table,
            'links' => $this->pages->links($request, $table),
        ]));
    }

    private function viewer(): ?UserInterface
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }
}
