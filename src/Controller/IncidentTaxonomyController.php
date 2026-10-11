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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Incident\Entity\TaxonomyKind;
use Uhifadhi\Incident\Entity\TaxonomySubcategory;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Exception\TaxonomyConflictException;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Repository\TaxonomyKindRepository;
use Uhifadhi\Incident\Repository\TaxonomySubcategoryRepository;
use Uhifadhi\Incident\Service\TaxonomyAdminService;

/**
 * THE AREA-SCOPED INCIDENT TAXONOMY ADMIN — the design's taxonomy.html, ported.
 *
 * ONE ROUTE, TWO DATA CONDITIONS. {@see self::show()} is the whole screen: a
 * populated area gets the two-pane manager (kinds on the left, the selected
 * kind's sub-categories and their behaviour blocks on the right); an area with no
 * kinds gets the empty start — the ghost sketch of a taxonomy's shape and the
 * "write the first kind" path. It is the same address, never a second route.
 *
 * AREA SCOPE IS THE ONE FACT. Every read and every write is confined to the area
 * in the URL: a kind or sub-category whose uuid does not belong to THIS area is a
 * 404, so no request can reach across into another area's list.
 *
 * A plain class, not AbstractController — a reusable bundle defines its services
 * explicitly, patterned on FrameworkBundle's TemplateController. Registered only
 * under the SecurityBundle guard (see UhifadhiIncidentBundle), because every
 * write here rides on `incident-vocabulary.configure` and there is nobody to grant it without
 * a firewall.
 *
 * THE COPY-FROM-ANOTHER-AREA PICKER IS DEFERRED, and the socket is marked in the
 * empty-state template. It needs to enumerate areas and read their NAMES, which
 * requires an area-directory contract that is not yet ruled; this admin ships the
 * "write the first kind" start and leaves the picker's socket open. See
 * templates/taxonomy/_empty.html.twig.
 *
 * WHICH MODULE THESE ROUTES BELONG TO, said once for the class. RegistryBundle
 * owns the per-area ledger and closes a parked module's pages before any
 * controller is asked — 404, not 403, because a parked module is not withheld:
 * the area is not running it. The class-level default below is how a route tells
 * the gate whose page it is.
 *
 * WITHOUT IT THESE ROUTES ARE NOT EXEMPT, THEY ARE GUESSED AT. The gate falls
 * back to reading `/areas/{uuid}/modules/{slug}/…` and matching the segment
 * against the catalogue, which happens to land here because the segment and the
 * slug are both `incidents`. That is an accident of naming, not a contract, and
 * it would end the moment a path moved. The area's uuid is in a parameter called
 * `uuid`, which is the gate's own default, so there is no
 * `_uhifadhi_module_area` to state.
 */
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => IncidentModuleProvider::SLUG])]
final class IncidentTaxonomyController
{
    /** The token id every taxonomy write carries. */
    public const string CSRF_TOKEN_ID = 'incident_taxonomy';

    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly TaxonomyAdminService $admin,
        private readonly TaxonomyKindRepository $kinds,
        private readonly TaxonomySubcategoryRepository $subcategories,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        /**
         * Whether the writing screens EXIST in this installation — they need
         * SecurityBundle. Whether THIS person may file is the other half, asked
         * in the template with `door('incidents.record', area)`.
         */
        private readonly bool $recordScreens = false,
    ) {
    }

    #[Route(
        '/areas/{uuid}/modules/incidents/kinds',
        name: 'incident_kinds',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET'],
        priority: 2,
    )]
    #[IsGranted('incident-vocabulary.read', subject: 'area')]
    public function show(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $kinds = $this->kinds->forArea($area);
        $selected = $this->selectedKind($kinds, $request->query->getString('kind'));

        return new Response($this->twig->render('@UhifadhiIncident/kinds/show.html.twig', [
            'area' => $area,
            'kinds' => $kinds,
            'selected' => $selected,
            // Which sub-category (if any) has its behaviour-block editor open.
            'editingBlocks' => null === $selected ? null : $this->openBlockEditor($selected, $request->query->getString('blocks')),
            'blockCatalogue' => BehaviorBlockEnum::inPickerOrder(),
            'moneyDirections' => MoneyDirectionEnum::cases(),
            // The one page action this screen draws. The way back is the strip,
            // the lit Configure and the crumb — never a button of its own.
            'recordScreens' => $this->recordScreens,
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]));
    }

    // ── kinds ────────────────────────────────────────────────────────────────

    #[Route('/areas/{uuid}/modules/incidents/kinds', name: 'incident_kinds_kind_create', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('incident-vocabulary.configure', subject: 'area')]
    public function createKind(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area): Response
    {
        $this->guardWrite($request);

        try {
            $kind = $this->admin->createKind($area, $request->request->getString('label'));

            return $this->backToManager($area, $kind);
        } catch (TaxonomyConflictException $e) {
            return $this->refused($request, $area, $this->selectedFromRequest($area, $request), $e);
        }
    }

    #[Route('/areas/{uuid}/modules/incidents/kinds/{kind}/rename', name: 'incident_kinds_kind_rename', requirements: ['uuid' => Requirement::UUID, 'kind' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('incident-vocabulary.configure', subject: 'area')]
    public function renameKind(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $kind): Response
    {
        $this->guardWrite($request);
        $entity = $this->kind($area, $kind);

        try {
            $this->admin->renameKind($entity, $request->request->getString('label'));
        } catch (TaxonomyConflictException $e) {
            return $this->refused($request, $area, $entity, $e);
        }

        return $this->backToManager($area, $entity);
    }

    #[Route('/areas/{uuid}/modules/incidents/kinds/{kind}/deactivate', name: 'incident_kinds_kind_deactivate', requirements: ['uuid' => Requirement::UUID, 'kind' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('incident-vocabulary.configure', subject: 'area')]
    public function deactivateKind(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $kind): Response
    {
        $this->guardWrite($request);
        $entity = $this->kind($area, $kind);
        $this->admin->deactivateKind($entity);

        return $this->backToManager($area, $entity);
    }

    #[Route('/areas/{uuid}/modules/incidents/kinds/{kind}/reactivate', name: 'incident_kinds_kind_reactivate', requirements: ['uuid' => Requirement::UUID, 'kind' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('incident-vocabulary.configure', subject: 'area')]
    public function reactivateKind(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $kind): Response
    {
        $this->guardWrite($request);
        $entity = $this->kind($area, $kind);
        $this->admin->reactivateKind($entity);

        return $this->backToManager($area, $entity);
    }

    // ── sub-categories ─────────────────────────────────────────────────────────

    #[Route('/areas/{uuid}/modules/incidents/kinds/{kind}/subcategories', name: 'incident_kinds_sub_create', requirements: ['uuid' => Requirement::UUID, 'kind' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('incident-vocabulary.configure', subject: 'area')]
    public function createSubcategory(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $kind): Response
    {
        $this->guardWrite($request);
        $entity = $this->kind($area, $kind);

        try {
            $this->admin->createSubcategory($entity, $request->request->getString('label'));
        } catch (TaxonomyConflictException $e) {
            return $this->refused($request, $area, $entity, $e);
        }

        return $this->backToManager($area, $entity);
    }

    #[Route('/areas/{uuid}/modules/incidents/kinds/subcategories/{sub}/rename', name: 'incident_kinds_sub_rename', requirements: ['uuid' => Requirement::UUID, 'sub' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('incident-vocabulary.configure', subject: 'area')]
    public function renameSubcategory(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $sub): Response
    {
        $this->guardWrite($request);
        $entity = $this->subcategory($area, $sub);

        try {
            $this->admin->renameSubcategory($entity, $request->request->getString('label'));
        } catch (TaxonomyConflictException $e) {
            return $this->refused($request, $area, $entity->getKind(), $e);
        }

        return $this->backToManager($area, $entity->getKind());
    }

    /**
     * WHAT THIS WORD DOES, in one save: the behaviour blocks, which way money runs
     * when the money block is on, and what the word promises. One panel and one
     * POST, because they are one decision — a term chosen without seeing the blocks
     * beside it is a term chosen in the dark.
     *
     * WHAT IT DOES NOT SAVE IS A LIST OF QUESTIONS. A word's questions are the
     * questions of the blocks ticked here, and nothing on this page can add one.
     */
    #[Route('/areas/{uuid}/modules/incidents/kinds/subcategories/{sub}/behaviour', name: 'incident_kinds_sub_behaviour', requirements: ['uuid' => Requirement::UUID, 'sub' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('incident-vocabulary.configure', subject: 'area')]
    public function setBehaviour(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $sub): Response
    {
        $this->guardWrite($request);
        $entity = $this->subcategory($area, $sub);

        $blocks = [];
        $chosen = $request->request->all()['blocks'] ?? [];
        if (\is_array($chosen)) {
            foreach ($chosen as $value) {
                $block = \is_string($value) ? BehaviorBlockEnum::tryFrom($value) : null;
                if (null !== $block) {
                    $blocks[] = $block;
                }
            }
        }

        $this->admin->setBlocks(
            $entity,
            $blocks,
            MoneyDirectionEnum::tryFrom($request->request->getString('money_direction')),
        );
        $this->admin->setTermHours($entity, $request->request->getInt('term_hours', TaxonomySubcategory::DEFAULT_TERM_HOURS));

        return $this->backToManager($area, $entity->getKind());
    }

    #[Route('/areas/{uuid}/modules/incidents/kinds/subcategories/{sub}/deactivate', name: 'incident_kinds_sub_deactivate', requirements: ['uuid' => Requirement::UUID, 'sub' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('incident-vocabulary.configure', subject: 'area')]
    public function deactivateSubcategory(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $sub): Response
    {
        $this->guardWrite($request);
        $entity = $this->subcategory($area, $sub);
        $this->admin->deactivateSubcategory($entity);

        return $this->backToManager($area, $entity->getKind());
    }

    #[Route('/areas/{uuid}/modules/incidents/kinds/subcategories/{sub}/reactivate', name: 'incident_kinds_sub_reactivate', requirements: ['uuid' => Requirement::UUID, 'sub' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('incident-vocabulary.configure', subject: 'area')]
    public function reactivateSubcategory(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $sub): Response
    {
        $this->guardWrite($request);
        $entity = $this->subcategory($area, $sub);
        $this->admin->reactivateSubcategory($entity);

        return $this->backToManager($area, $entity->getKind());
    }

    // ── the shared machinery ───────────────────────────────────────────────────

    /**
     * Which kind the manager opens on: the one named in the query if it is this
     * area's and it exists, else the first. Null only when the area is empty.
     *
     * @param list<TaxonomyKind> $kinds
     */
    private function selectedKind(array $kinds, string $uuid): ?TaxonomyKind
    {
        if ([] === $kinds) {
            return null;
        }
        if ('' !== $uuid) {
            foreach ($kinds as $kind) {
                if ($kind->getUuid()->toRfc4122() === $uuid) {
                    return $kind;
                }
            }
        }

        return $kinds[0];
    }

    /** The sub-category whose block editor is open, if it belongs to the selected kind. */
    private function openBlockEditor(TaxonomyKind $selected, string $uuid): ?TaxonomySubcategory
    {
        if ('' === $uuid) {
            return null;
        }
        foreach ($selected->getSubcategories() as $subcategory) {
            if ($subcategory->getUuid()->toRfc4122() === $uuid) {
                return $subcategory;
            }
        }

        return null;
    }

    private function kind(AreaOfInterest $area, string $uuid): TaxonomyKind
    {
        $kind = $this->kinds->findOneByAreaAndUuid($area, $uuid);
        if (null === $kind) {
            throw new NotFoundHttpException('No such kind of incident in this area.');
        }

        return $kind;
    }

    private function subcategory(AreaOfInterest $area, string $uuid): TaxonomySubcategory
    {
        $subcategory = $this->subcategories->findOneByAreaAndUuid($area, $uuid);
        if (null === $subcategory) {
            throw new NotFoundHttpException('No such sub-category in this area.');
        }

        return $subcategory;
    }

    /** The kind the request meant to be looking at, for putting a refusal back in place. */
    private function selectedFromRequest(AreaOfInterest $area, Request $request): ?TaxonomyKind
    {
        $uuid = $request->query->getString('kind');

        return '' === $uuid ? null : $this->kinds->findOneByAreaAndUuid($area, $uuid);
    }

    /** Post/redirect/get back to the manager, holding the kind that was in play. */
    private function backToManager(AreaOfInterest $area, ?TaxonomyKind $selected = null): RedirectResponse
    {
        $parameters = ['uuid' => $area->getUuidString()];
        if (null !== $selected) {
            $parameters['kind'] = $selected->getUuid()->toRfc4122();
        }

        return new RedirectResponse($this->router->generate('incident_kinds', $parameters));
    }

    /** A refused write flashes why, beside where it happened, and returns to the manager. */
    private function refused(Request $request, AreaOfInterest $area, ?TaxonomyKind $selected, TaxonomyConflictException $e): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $e->getMessage());
        }

        return $this->backToManager($area, $selected);
    }

    private function guardWrite(Request $request): void
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString('_token')))) {
            throw new AccessDeniedException('Invalid CSRF token for the taxonomy admin.');
        }
    }
}
