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

namespace Uhifadhi\Incident\Service;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Incident\Access\IncidentConcerns;
use Uhifadhi\Incident\Model\IncidentFilter;
use Uhifadhi\Incident\Model\IncidentOrgArea;
use Uhifadhi\Incident\Model\IncidentOrgKind;
use Uhifadhi\Incident\Model\IncidentOrgKindRow;
use Uhifadhi\Incident\Model\IncidentOrgKinds;
use Uhifadhi\Incident\Model\IncidentOrgQuery;
use Uhifadhi\Incident\Model\IncidentOrgRegister;
use Uhifadhi\Incident\Model\IncidentOrgRow;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Repository\TaxonomyKindRepository;

/**
 * INCIDENTS READ ACROSS EVERY AREA — the area pages one scope wider.
 *
 * EVERY FIGURE IS THE AREA READING WITH THE AREA FILTER WIDENED. This class
 * counts nothing of its own: it resolves which areas a scope reaches and asks
 * each of them what the area's own pages ask — the register's one filtered
 * query, the kinds page's figures — and folds what comes back. A second aggregate written for this scope would be two numbers
 * for one question.
 *
 * ONLY AREAS THAT RUN THE MODULE, AND ONLY AREAS THE READER MAY READ IT IN.
 * The scope is already narrowed to what the account may open; an area that
 * has not switched incidents on has no incidents to show, and one whose
 * register this reader may not open is not shown here either, because the
 * organization page is the area pages added and never more than them.
 */
final class IncidentOrgService
{
    /** @var list<AreaOfInterest>|null */
    private ?array $declared = null;

    public function __construct(
        private readonly AreaOfInterestRepository $areas,
        private readonly AreaModuleService $areaModules,
        private readonly IncidentRepository $incidents,
        private readonly TaxonomyKindRepository $kinds,
        private readonly IncidentKindsOverviewService $kindsOverview,
        private readonly ?AuthorizationCheckerInterface $authorization = null,
    ) {
    }

    /**
     * THE AREAS A SCOPE REACHES, in the declared order, that run this module
     * and whose register the reader may open.
     *
     * @param list<Scope>|null $available everything the reader was offered, or null for every area
     *
     * @return list<IncidentOrgArea>
     */
    public function areasIn(Scope $scope, ?array $available): array
    {
        $offered = null;
        if (null !== $available) {
            $offered = [];
            foreach ($available as $one) {
                if (null !== $one->areaUuid) {
                    $offered[$one->areaUuid] = true;
                }
            }
        }

        $reached = [];
        foreach ($this->declaredOrder() as $index => $area) {
            $uuid = (string) $area->getUuidString();
            if (!$scope->isOrganization() && $uuid !== $scope->areaUuid) {
                continue;
            }
            if ($scope->isOrganization() && null !== $offered && !isset($offered[$uuid])) {
                continue;
            }
            if (!$this->areaModules->isActive($area, IncidentModuleProvider::SLUG) || !$this->mayRead($area)) {
                continue;
            }
            $reached[] = new IncidentOrgArea($area, $index + 1);
        }

        return $reached;
    }

    /**
     * THE REGISTER: each area's own filtered query under the month and the
     * search, then narrowed by the bar's other axes and the scope.
     *
     * @param list<IncidentOrgArea> $organization every area the reader may open, for the bar's counts
     */
    public function register(array $organization, IncidentOrgQuery $query): IncidentOrgRegister
    {
        $base = [];
        $areas = [];
        /** @var array<string, array{label: string, catIndex: int, count: int}> $categories */
        $categories = [];
        $departments = [];

        foreach ($organization as $one) {
            foreach ($this->kinds->forArea($one->area) as $kind) {
                $categories[$kind->getCode()] ??= ['label' => $kind->getLabel(), 'catIndex' => $kind->catIndex(), 'count' => 0];
                foreach ($kind->getLeads() as $lead) {
                    $departments[$lead] ??= 0;
                }
            }

            $filter = new IncidentFilter($one->area, $query->from, $query->to, search: $query->search);
            $found = $this->incidents->findFiltered($filter);
            $areas[] = ['area' => $one, 'count' => \count($found)];
            foreach ($found as $incident) {
                $base[] = new IncidentOrgRow($one, $incident);
                $kind = $incident->getKind();
                $word = $categories[$kind->getCode()] ?? ['label' => $kind->getLabel(), 'catIndex' => $kind->catIndex(), 'count' => 0];
                ++$word['count'];
                $categories[$kind->getCode()] = $word;
                foreach ($incident->getKind()->getLeads() as $lead) {
                    $departments[$lead] = ($departments[$lead] ?? 0) + 1;
                }
            }
        }

        $rows = array_values(array_filter($base, static fn (IncidentOrgRow $row): bool => self::lets($query, $row)));
        usort($rows, static fn (IncidentOrgRow $a, IncidentOrgRow $b): int => [$b->incident->getReportedAt(), $b->incident->getReference()]
            <=> [$a->incident->getReportedAt(), $a->incident->getReference()]);
        ksort($departments);

        return new IncidentOrgRegister($rows, \count($base), $areas, $categories, $departments);
    }

    private static function lets(IncidentOrgQuery $query, IncidentOrgRow $row): bool
    {
        $kind = $row->incident->getKind();

        return (null === $query->area || $row->area->uuid() === $query->area)
            && (null === $query->category || $kind->getCode() === $query->category)
            && (null === $query->status || $row->incident->getStatus() === $query->status)
            && (null === $query->department || \in_array($query->department, $kind->getLeads(), true));
    }

    /**
     * THE TAXONOMY ACROSS THE SCOPE: each area's own kinds page, folded by the
     * wire-code the areas share, and the chosen word's areas after the bar.
     *
     * @param list<IncidentOrgArea> $areas
     * @param string|null           $use   'active' or 'retired' narrows the word's areas; anything else does not
     */
    public function kinds(array $areas, ?string $code, ?string $use, ?string $search, \DateTimeImmutable $now): IncidentOrgKinds
    {
        /** @var array<string, array{label: string, catIndex: int, rows: list<IncidentOrgKindRow>}> $words */
        $words = [];
        foreach ($areas as $one) {
            foreach ($this->kindsOverview->build($one->area, null, $now)->kinds as $figures) {
                $words[$figures->code] ??= ['label' => $figures->label, 'catIndex' => $figures->catIndex, 'rows' => []];
                $words[$figures->code]['rows'][] = new IncidentOrgKindRow($one, $figures);
            }
        }

        $kinds = [];
        foreach ($words as $wireCode => $word) {
            $kinds[] = new IncidentOrgKind((string) $wireCode, $word['label'], $word['catIndex'], $word['rows']);
        }

        $selected = null;
        foreach ($kinds as $kind) {
            if ($kind->code === $code) {
                $selected = $kind;
            }
        }
        $selected ??= $kinds[0] ?? null;

        $needle = null === $search ? null : mb_strtolower($search);
        $rows = array_values(array_filter(
            $selected->rows ?? [],
            static fn (IncidentOrgKindRow $row): bool => ('active' !== $use || $row->figures->active)
                && ('retired' !== $use || !$row->figures->active)
                && (null === $needle || str_contains(mb_strtolower($row->area->name()), $needle)),
        ));

        return new IncidentOrgKinds($kinds, $selected, $rows);
    }

    private function mayRead(AreaOfInterest $area): bool
    {
        return null === $this->authorization
            || $this->authorization->isGranted((string) Grant::of(IncidentConcerns::INCIDENTS, Verb::Read), $area);
    }

    /**
     * THE AREAS AS THE PRODUCT DECLARES THEM — one order, everywhere, so an
     * area's swatch is the same on every surface that draws one.
     *
     * @return list<AreaOfInterest>
     */
    private function declaredOrder(): array
    {
        return $this->declared ??= $this->areas->findBy([], ['id' => 'ASC']);
    }
}
