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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Incident\Entity\TaxonomySubcategory;
use Uhifadhi\Incident\Enum\IncidentSeverityEnum;
use Uhifadhi\Incident\Enum\IncidentSourceEnum;
use Uhifadhi\Incident\Model\BlockAnswers;
use Uhifadhi\Incident\Model\BlockQuestionCatalogue;
use Uhifadhi\Incident\Model\BlockQuestionSet;
use Uhifadhi\Incident\Model\IncidentPlacement;
use Uhifadhi\Incident\Model\IncidentPrefill;
use Uhifadhi\Incident\Model\ReportChecklist;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Repository\TaxonomyKindRepository;
use Uhifadhi\Incident\Repository\TaxonomySubcategoryRepository;
use Uhifadhi\Incident\Service\AreaListService;
use Uhifadhi\Incident\Service\IncidentBlockAnswerService;
use Uhifadhi\Incident\Service\IncidentPlacementService;
use Uhifadhi\Incident\Service\IncidentReportService;

/**
 * REPORTING AN INCIDENT — what kind, what happened, where, and the defining
 * question of every block the category switched on.
 *
 * ONE CONTAINER, THE FULL PAGE (the ruled direction A, with D's quick-file
 * discipline inside it). Filing gets an ADDRESS of its own, whatever the entry
 * point:
 *
 *   FILING FROM A RECORD — another module's "file as incident" button, or the
 *   register's report control carrying context — renders the page with the source
 *   card riding at its head, so the thing being filed about is right there.
 *
 *   STANDALONE FILING — a deep link, a fresh tab, the dashboard's Report button —
 *   renders the same page with no source card.
 *
 * A page reloads, deep-links, prints, and cannot be thrown away by a click beside
 * it — which is why the slide-over drawer this flow once opened for a
 * record-borne filing is retired. There is one container and one POST, refused
 * for the same three reasons.
 *
 * STEP 2 IS THE SUB-CATEGORY'S OWN, AND ITS QUESTIONS COME FROM ITS BLOCKS. One
 * fold per behaviour block the sub-category switched on, in the order the kinds
 * editor holds them, each asking exactly what {@see BlockQuestionCatalogue} says
 * it asks. A block that is off is ABSENT — choose a natural mortality and there
 * is no money fold in the document at all. Not disabled. Absent.
 *
 * AND EVERY SWITCHED-ON BLOCK'S DEFINING ANSWER GATES THE FILING: the species,
 * one count row, the method, a party with a role and a name, a seizure item and
 * count, the figure, the condition, a sample type and reference, an injury row, a
 * measure row, the kind of place and which one, the permit and licence status. A
 * block that records nothing is worse than a block that is absent. The paperwork
 * — contacts, ID numbers, custody references, dates, facilities — never gates,
 * and the browser says all of this sooner than the endpoint without being the
 * authority for any of it ({@see IncidentBlockAnswerService}).
 *
 * ARRIVING FROM AN OBSERVATION: {@see IncidentPrefill} reads the hand-off's query
 * string. Everything it carries is a guess the filer may overrule, except the
 * provenance link, which is written once and never again.
 *
 * The permission is `incidents.record`. The design's IN·R1 card says filing needs
 * "anyone with Modules access · not a permission of its own"; a POST that CREATES
 * a record still has to be guarded by something a host can grant, so the module
 * DECLARES this permission and a deployment that agrees with the design grants it
 * to everyone who can reach the module. See `docs/permissions.md`.
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
final class IncidentReportController
{
    /** The token id the report form carries. */
    public const string CSRF_TOKEN_ID = 'incident_report';

    /** What the register can print: {@see Incident::$title} is varchar(200). */
    private const int TITLE_LIMIT = 200;

    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly IncidentReportService $reports,
        private readonly IncidentBlockAnswerService $blockAnswers,
        /**
         * THE WORDS THIS AREA'S FOUR LIST QUESTIONS OFFER — asked of the same
         * service the Lists editor writes through, so the form can never offer a
         * word the editor could not have produced.
         */
        private readonly AreaListService $areaLists,
        private readonly TaxonomyKindRepository $kinds,
        private readonly TaxonomySubcategoryRepository $subcategories,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly IncidentPlacementService $placements,
    ) {
    }

    #[Route(
        '/areas/{uuid}/modules/incidents/new',
        name: 'incident_new',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET'],
        priority: 2,
    )]
    #[IsGranted('incidents.record', subject: 'area')]
    public function new(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $prefill = IncidentPrefill::fromRequest($request);

        return new Response($this->render(
            $area,
            $prefill,
            null === $prefill->subcategorySlug ? null : $this->subcategories->findOneByAreaAndCode($area, $prefill->subcategorySlug),
            [],
        ));
    }

    /**
     * FILE IT. Almost nothing is required, because a half-remembered report that
     * exists beats a perfect one that was never filed — the two things that ARE
     * required are the only two an incident cannot be without: what kind of thing
     * happened, and where.
     */
    #[Route(
        '/areas/{uuid}/modules/incidents',
        name: 'incident_create',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('incidents.record', subject: 'area')]
    public function create(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $this->denyUnlessCsrfValid($request);

        $prefill = IncidentPrefill::fromRequest($request);
        // THIS AREA'S WORD, never another area's with the same code: the form
        // posts a wire-code and the area in the path is what gives it meaning.
        $subcategory = $this->subcategories->findOneByAreaAndCode($area, $request->request->getString('subcategory'));
        $position = self::positionFrom($request) ?? $prefill->position();
        // CLAMPED, NEVER REFUSED. The line arrives prefilled from the source
        // record's note, which is written to be read rather than to fit a column,
        // so a long one is stored short instead of costing somebody their report.
        // Nothing is lost: the note travels verbatim into the narrative and the
        // provenance link keeps the original reachable forever.
        $title = mb_substr(trim($request->request->getString('title')), 0, self::TITLE_LIMIT);

        // THE BLOCKS' ANSWERS, READ AGAINST WHAT THE CHOSEN SUB-CATEGORY ASKS.
        // Nothing is read for a sub-category that was not chosen, so a form that
        // carried every sub-category's questions files only the one it picked.
        $answers = null === $subcategory
            ? new BlockAnswers()
            : $this->blockAnswers->read($subcategory, $request->request->all('blocks'));
        $missing = null === $subcategory ? [] : $this->blockAnswers->missing($subcategory, $answers);

        $errors = [];
        if (null === $subcategory) {
            $errors['subcategory'] = 'Choose what kind of incident this was.';
        }
        if (null === $position) {
            $errors['position'] = 'An incident happened somewhere — pick the place on the map.';
        }
        if ('' === $title) {
            $errors['title'] = 'One line saying what happened.';
        }
        if ([] !== $missing) {
            // A BLOCK THAT RECORDS NOTHING IS WORSE THAN AN ABSENT ONE, so the
            // refusal names every defining answer that is still unanswered — the
            // same line the footer was already showing.
            $errors['blocks'] = 'Every block that is on needs its own first answer: '.implode(' · ', $missing).'.';
        }

        // OUTSIDE THE AREA'S BOUNDARY: the area's setting decides whether the
        // web form files it as it is, once the filer chose to keep the point,
        // or not until the point is inside.
        $placement = null === $position ? null : $this->placements->judge($area, $position);
        $kept = 'outside' === $request->request->getString('placement');
        $heldOutside = null !== $placement && ($placement->refused() || ($placement->needsConsent() && !$kept));

        if (null === $subcategory || null === $position || '' === $title || [] !== $missing || $heldOutside) {
            // 422 and the form back: the request was understood and simply cannot
            // be stored, which is how every recording screen in this deployment
            // answers a rejected form. It comes back on the same page it was made
            // on, with the source card and everything typed still there — being
            // refused is not a new entry point.
            return new Response(
                $this->render($area, $prefill, $subcategory, $errors, $placement, $kept),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $incident = $this->reports->file(
            area: $area,
            subcategory: $subcategory,
            title: $title,
            position: $position,
            now: new \DateTimeImmutable(),
            severity: IncidentSeverityEnum::tryFrom($request->request->getString('severity')) ?? IncidentSeverityEnum::Moderate,
            // The wire token, mapped once and deliberately — never a fallback
            // that happens to land on the right case.
            source: IncidentSourceEnum::forToken($request->request->getString('source'))
                ?? ($prefill->hasProvenance() ? IncidentSourceEnum::PatrolObservation : IncidentSourceEnum::Direct),
            occurredAt: self::occurredAtFrom($request) ?? $prefill->occurredAt,
            narrative: self::narrativeFrom($request) ?? $prefill->note,
            reportedBy: $this->filer(),
            prefill: $prefill,
            blockAnswers: $answers,
        );

        $this->flash($request, \sprintf('%s filed. It starts at reported and cannot skip verification.', $incident->getReference()));

        return new RedirectResponse($this->router->generate('incident_show', [
            'uuid' => $area->getUuidString(),
            'reference' => $incident->getReference(),
        ]));
    }

    /**
     * THE FLOW, ON ITS ONE PAGE — rendered in one place so a fresh form and a
     * refused one can never disagree about anything.
     *
     * @param array<string, string> $errors
     */
    private function render(AreaOfInterest $area, IncidentPrefill $prefill, ?TaxonomySubcategory $chosen, array $errors, ?IncidentPlacement $placement = null, bool $kept = false): string
    {
        $fromARecord = $prefill->hasProvenance();
        $query = $prefill->toQuery();
        $kinds = $this->kinds->forArea($area);
        $blockSets = self::blockSetsFor($kinds);

        return $this->twig->render('@UhifadhiIncident/report/show.html.twig', [
            'area' => $area,
            'now' => new \DateTimeImmutable(),
            'kinds' => $kinds,
            'prefill' => $prefill,
            'chosen' => $chosen,
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
            'errors' => $errors,
            // WHERE THE POSTED POINT LIES AGAINST THE AREA, for the banner over
            // the Where row; null on a fresh form, whose point nobody has judged.
            'placement' => $placement,
            'placementKept' => $kept,
            // WHAT EACH SUB-CATEGORY'S BLOCKS ASK, keyed by wire-code: the form
            // renders one fold per block, so the page never types a question of
            // its own and a block that is off has no markup at all.
            'blockSets' => $blockSets,
            // THE PER-AREA LISTS the catalogue's four list questions read from —
            // which animal, by what method, what the ground is used for, which
            // named place. THIS AREA'S OWN WORDS, written in the Lists section of
            // the configure page, and only the ones still offered: retiring a word
            // takes it off this form and leaves it on every record already filed
            // under it. A list with nothing in it draws an empty select beside the
            // typed `other`, which is a legitimate state and not a bug.
            'areaLists' => $this->areaLists->wordsFor($area),
            // THE FORM POSTS BACK TO ITS OWN ENTRY POINT. Without the hand-off on the
            // action, pressing File would drop the provenance, the source card and
            // the container all at once.
            'createUrl' => $this->router->generate('incident_create', ['uuid' => $area->getUuidString()])
                .([] === $query ? '' : '?'.http_build_query($query)),
            // ONE WAY OUT, AND IT SAYS WHERE IT GOES: back to the record this
            // filing came from, or to the register it was started from. Never a
            // dismissal.
            'cancelUrl' => $fromARecord && null !== $prefill->backUrl
                ? $prefill->backUrl
                : $this->router->generate('incident_dashboard', ['uuid' => $area->getUuidString()]),
            // WHAT EACH SUB-CATEGORY ASKS, as the rail states it — built from the
            // same sets the form renders step 2 from, so the rail cannot describe a
            // form this page did not draw.
            'checklists' => self::checklistsFor($kinds, $blockSets),
        ]);
    }

    /** The point the form carries, as GeoJSON text, or null where it carried none. */
    private static function positionFrom(Request $request): ?string
    {
        $lat = $request->request->getString('lat');
        $lng = $request->request->getString('lng');
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        return \sprintf('{"type":"Point","coordinates":[%.6F,%.6F]}', (float) $lng, (float) $lat);
    }

    /**
     * WHEN IT HAPPENED, READ IN THE CLOCK THE FILER WAS LOOKING AT.
     *
     * A `datetime-local` field posts a WALL CLOCK and no zone, so the same string
     * is a different instant to every reader. Parsing it with no zone at all reads
     * it in whatever zone the SERVER runs in — a zone nobody on the page has any
     * reason to be in, and the surest way to store 18:32 for an event at 21:32.
     * So the form states the reader's zone beside the clock and this reads the one
     * in the other.
     */
    private static function occurredAtFrom(Request $request): ?\DateTimeImmutable
    {
        $raw = trim($request->request->getString('occurred_at'));
        if ('' === $raw) {
            return null;
        }

        try {
            // THE REGISTER'S OWN ZONE, because the column is a naive
            // `datetime_immutable`: Doctrine writes the WALL CLOCK the object
            // carries, so a moment held at +03:00 would be stored three hours
            // early. Converting here is what makes the zone above a reading
            // instruction rather than a relabelling.
            return new \DateTimeImmutable($raw, self::filersZone($request))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            // "I do not know when" is a legitimate answer to that question, and a
            // typo in a date must never stand between a ranger and a filed report.
            return null;
        }
    }

    /**
     * THE ZONE THE FORM'S CLOCK WAS READ IN, and UTC wherever the form did not
     * say.
     *
     * UTC IS THE FALLBACK AND THE SERVER'S OWN ZONE IS NEVER IT. A page with no
     * JavaScript prints the field in UTC and leaves the zone field empty, so
     * reading it as UTC gives that reader back exactly the instant they were
     * shown. A zone this build of the tz database has never heard of answers the
     * same way: a filing is not worth losing over it.
     */
    private static function filersZone(Request $request): \DateTimeZone
    {
        $named = trim($request->request->getString('occurred_at_zone'));

        try {
            return '' === $named ? new \DateTimeZone('UTC') : new \DateTimeZone($named);
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    private static function narrativeFrom(Request $request): ?string
    {
        $narrative = trim($request->request->getString('narrative'));

        return '' !== $narrative ? $narrative : null;
    }

    /**
     * WHAT EVERY SUB-CATEGORY IN THIS AREA ASKS, keyed by its wire-code.
     *
     * The form renders one fold per block a sub-category switched on, so the page
     * needs the questions of all of them: it draws every sub-category's step 2 and
     * hides all but the chosen one, which is what lets choosing a word swap the
     * questions without a round trip. The money direction comes from the row,
     * because it is what names the one question the money block asks.
     *
     * @param list<\Uhifadhi\Incident\Entity\TaxonomyKind> $kinds
     *
     * @return array<string, list<BlockQuestionSet>>
     */
    private static function blockSetsFor(array $kinds): array
    {
        $sets = [];
        foreach ($kinds as $kind) {
            foreach ($kind->getSubcategories() as $subcategory) {
                $sets[$subcategory->getCode()] = BlockQuestionCatalogue::forBlocks(
                    $subcategory->getBlocks(),
                    $subcategory->getMoneyDirection(),
                );
            }
        }

        return $sets;
    }

    /**
     * WHAT EACH SUB-CATEGORY ASKS, AS THE RAIL STATES IT, keyed by wire-code.
     *
     * Built from the block sets the form is already rendering step 2 from — the
     * rail describes the form this page drew, never a second reading of the
     * taxonomy. Keyed the same way, so the controller that swaps a field set swaps
     * the card that names it.
     *
     * @param list<\Uhifadhi\Incident\Entity\TaxonomyKind> $kinds
     * @param array<string, list<BlockQuestionSet>>        $blockSets
     *
     * @return array<string, ReportChecklist>
     */
    private static function checklistsFor(array $kinds, array $blockSets): array
    {
        $checklists = [];
        foreach ($kinds as $kind) {
            foreach ($kind->getSubcategories() as $subcategory) {
                $checklists[$subcategory->getCode()] = ReportChecklist::for(
                    $subcategory->getLabel(),
                    $blockSets[$subcategory->getCode()] ?? [],
                );
            }
        }

        return $checklists;
    }

    private function denyUnlessCsrfValid(Request $request): void
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString('_token')))) {
            throw new AccessDeniedException('Invalid CSRF token for the report form.');
        }
    }

    private function filer(): ?UserInterface
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }

    private function flash(Request $request, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $message);
        }
    }
}
