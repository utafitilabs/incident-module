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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\ShellBundle\Service\Scopes;
use Uhifadhi\Bundle\ShellBundle\Service\TablePageService;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Model\IncidentOrgArea;
use Uhifadhi\Incident\Model\IncidentOrgQuery;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Service\IncidentOrgService;

/**
 * INCIDENTS AT ORGANIZATION SCOPE — three screens, read across every area.
 *
 * THE SHELL DRAWS THE CHROME. The row in Observatory, the tab strip and the
 * scope control are the shell's, mounted from what this module's provider
 * names in `orgPages()`; nothing here writes a sidebar item, a tab or a
 * control.
 *
 * EVERY FIGURE IS THE AREA READING ONE SCOPE WIDER: {@see IncidentOrgService}
 * walks the areas the scope reaches and folds what the area pages' own
 * services answer.
 *
 * THE MODULE MARKER IS DECLARED and names no area, so the parking gate has
 * nothing to close here: an area that parked the module simply is not one of
 * the areas this reading reaches.
 *
 * LEAN, and without a base class, wired explicitly as FrameworkBundle's own
 * TemplateController is.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html
 * @see vendor/symfony/framework-bundle/Controller/TemplateController.php
 */
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => IncidentModuleProvider::SLUG])]
final readonly class IncidentOrgController
{
    public const string OVERVIEW_ROUTE = 'incident_org_dashboard';
    public const string LIST_ROUTE = 'incident_org_list';
    public const string KINDS_ROUTE = 'incident_org_kinds';

    public function __construct(
        private Environment $twig,
        private IncidentOrgService $org,
        private Scopes $scopes,
        private TablePageService $pages,
    ) {
    }

    #[Route('/incidents', name: self::OVERVIEW_ROUTE, methods: ['GET'])]
    #[IsGranted('areas.read')]
    public function overview(): Response
    {
        [$scope, $areas, $now] = $this->reading();

        return new Response($this->twig->render('@UhifadhiIncident/org/overview.html.twig', [
            'scope' => $scope,
            'now' => $now,
            'summary' => $this->org->summary($areas, $now),
        ]));
    }

    #[Route('/incidents/list', name: self::LIST_ROUTE, methods: ['GET'])]
    #[IsGranted('areas.read')]
    public function list(Request $request): Response
    {
        [$scope, $areas, $now] = $this->reading();
        [$from, $to] = IncidentController::windowFor($request, $now);
        $query = IncidentOrgQuery::fromRequest($request, $scope->areaUuid, $from, $to);

        // THE BAR COUNTS THE WHOLE ORGANIZATION the reader may open, whatever
        // the scope, so the area chip offers every area with its number.
        $organization = $scope->isOrganization() ? $areas : $this->org->areasIn(Scope::organization(), $this->scopes->available());
        $register = $this->org->register($organization, $query);
        $table = $this->pages->read($request, \count($register->rows));

        return new Response($this->twig->render('@UhifadhiIncident/org/list.html.twig', [
            'scope' => $scope,
            'now' => $now,
            'query' => $query,
            'register' => $register,
            'rows' => $table->slice($register->rows),
            'statuses' => IncidentStatusEnum::ordered(),
            'table' => $table,
            'links' => $this->pages->links($request, $table),
        ]));
    }

    #[Route('/incidents/kinds', name: self::KINDS_ROUTE, methods: ['GET'])]
    #[IsGranted('areas.read')]
    public function kinds(Request $request): Response
    {
        [$scope, $areas, $now] = $this->reading();
        $use = self::asked($request, 'use');
        $search = self::asked($request, 'q');
        $kinds = $this->org->kinds($areas, self::asked($request, 'kind'), $use, $search, $now);
        $table = $this->pages->read($request, \count($kinds->rows));

        return new Response($this->twig->render('@UhifadhiIncident/org/kinds.html.twig', [
            'scope' => $scope,
            'now' => $now,
            'kinds' => $kinds,
            'rows' => $table->slice($kinds->rows),
            'chosen' => array_filter(['area' => $scope->areaUuid, 'kind' => $kinds->selected?->code, 'use' => $use, 'q' => $search], static fn (?string $v): bool => null !== $v),
            'table' => $table,
            'links' => $this->pages->links($request, $table),
        ]));
    }

    private static function asked(Request $request, string $name): ?string
    {
        $value = trim($request->query->getString($name));

        return '' === $value ? null : $value;
    }

    /**
     * WHAT EVERY ONE OF THE THREE READS — one scope, its areas, one instant.
     *
     * @return array{0: Scope, 1: list<IncidentOrgArea>, 2: \DateTimeImmutable}
     */
    private function reading(): array
    {
        $scope = $this->scopes->current() ?? Scope::organization();

        return [$scope, $this->org->areasIn($scope, $this->scopes->available()), new \DateTimeImmutable()];
    }
}
