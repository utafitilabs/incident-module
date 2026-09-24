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

namespace Uhifadhi\Incident\Tests\Integration\Module;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\DepartmentRef;
use Uhifadhi\Incident\Access\IncidentDoors;
use Uhifadhi\Incident\Entity\IncidentMoney;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Module\IncidentDepartmentKpiProvider;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Service\IncidentTransitionService;
use Uhifadhi\Incident\Tests\Integration\Fixtures\FixedPermissionVoter;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * THE INCIDENTS FIGURES A DEPARTMENT'S PERFORMANCE PAGE PRINTS, against real rows.
 *
 * THE SCOPE IS THE THING UNDER TEST. The host asks only departments that attach
 * this module, so the figures are the scope's incidents: one area's when the ref
 * names an area, every area's when it names none — whoever recorded them, seated
 * or not.
 */
final class IncidentDepartmentKpiProviderTest extends IntegrationTestCase
{
    private function provider(): IncidentDepartmentKpiProvider
    {
        /** @var IncidentRepository $incidents */
        $incidents = static::getContainer()->get(IncidentRepository::class);
        /** @var IncidentDoors $doors */
        $doors = static::getContainer()->get('test_public.incident.access.doors');

        return new IncidentDepartmentKpiProvider($incidents, $doors, 'incidents', 'Incidents', 'TZS');
    }

    /**
     * THE MONEY PLATES NEED A READER WHO MAY READ MONEY, and the doors fail
     * closed on nobody at all — so every test below that expects them signs
     * somebody in who holds `case-money.read` across the organization. The
     * one that expects them WITHHELD signs in the clerk instead.
     */
    private function signInAReaderOfMoney(): void
    {
        $this->signIn($this->aUser(FixedPermissionVoter::MANAGER_EMAIL, 'Sara', 'Laizer'));
    }

    private function transitions(): IncidentTransitionService
    {
        /** @var IncidentTransitionService $transitions */
        $transitions = $this->service('incident.transitions');

        return $transitions;
    }

    private static function ref(Department $department, ?AreaOfInterest $area = null): DepartmentRef
    {
        $id = $department->getId();
        self::assertNotNull($id, 'A department has to be stored before a ref can name it.');

        return new DepartmentRef($id, (string) $department->getName(), null, $area?->getUuidString());
    }

    /** @return array<string, DepartmentKpi> */
    private function kpisFor(Department $department, \DateTimeImmutable $now, ?AreaOfInterest $area = null): array
    {
        $byKey = [];
        foreach ($this->provider()->kpisFor(self::ref($department, $area), $now) as $kpi) {
            $byKey[$kpi->key] = $kpi;
        }

        return $byKey;
    }

    /** The slug must match the module's, or the host never asks this provider anything. */
    public function testItAnswersForTheIncidentsModule(): void
    {
        self::assertSame('incidents', $this->provider()->moduleSlug());
    }

    /**
     * NOTHING IN SCOPE IS NOTHING REPORTED — not a row of zeros. Incidents
     * elsewhere, or outside both windows, do not make a reading.
     */
    public function testNothingInScopeInEitherWindowReportsNothing(): void
    {
        $north = $this->anAreaWithKinds('North Sector');
        $south = $this->anAreaWithKinds('South Sector');
        $department = $this->aDepartment();
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $this->anIncident($south, at: $now->modify('-1 day'));
        $this->anIncident($north, at: new \DateTimeImmutable('2026-05-10 09:00:00'));

        self::assertSame([], $this->provider()->kpisFor(self::ref($department, $north), $now));
        self::assertSame([], $this->provider()->kpisFor(self::ref($department), new \DateTimeImmutable('2026-12-22')));
    }

    public function testItCountsTheIncidentsRecordedInTheArea(): void
    {
        $area = $this->anAreaWithKinds();
        $department = $this->aDepartment();
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $this->anIncident($area, at: $now->modify('-2 days'));
        $this->anIncident($area, 'snaring', 'Snare line lifted', $now->modify('-1 day'));

        $kpis = $this->kpisFor($department, $now, $area);

        self::assertSame(2.0, $kpis['incidents']->value);
        self::assertSame('Incidents recorded', $kpis['incidents']->label);
        self::assertSame('incidents', $kpis['incidents']->moduleSlug);
        self::assertSame('Incidents module · incidents recorded in this area', $kpis['incidents']->caption);
    }

    public function testAnOrganizationWideCaptionSaysTheOrganization(): void
    {
        $area = $this->anAreaWithKinds();
        $department = $this->aDepartment();
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $this->anIncident($area, at: $now->modify('-1 day'));

        self::assertSame('Incidents module · incidents recorded across the organization', $this->kpisFor($department, $now)['incidents']->caption);
    }

    /** An incident filed by somebody who holds no position still counts for its area. */
    public function testAnIncidentFiledBySomebodyWithNoPositionCountsForItsArea(): void
    {
        $area = $this->anAreaWithKinds();
        $department = $this->aDepartment();
        $unseated = $this->aUser('volunteer@example.test', 'Neema', 'Laizer');
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $this->anIncident($area, at: $now->modify('-1 day'), reportedBy: $unseated);

        self::assertSame(1.0, $this->kpisFor($department, $now, $area)['incidents']->value);
    }

    /** The recorder's seat in another department does not take the incident out of scope. */
    public function testAnIncidentFiledBySomebodySeatedInAnotherDepartmentStillCounts(): void
    {
        $area = $this->anAreaWithKinds();
        $protection = $this->aDepartment('Protection Service');
        $ecology = $this->aDepartment('Ecology');
        $ecologist = $this->aUser('ecologist@example.test', 'Anna', 'Kileo', $ecology);
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $this->anIncident($area, 'roadkill', 'Zebra roadkill', $now->modify('-1 day'), $ecologist);

        self::assertSame(1.0, $this->kpisFor($protection, $now, $area)['incidents']->value);
    }

    /** Two departments scoped to the same area read the same figures. */
    public function testTwoDepartmentsInTheSameAreaGetIdenticalFigures(): void
    {
        $this->signInAReaderOfMoney();
        $area = $this->anAreaWithKinds();
        $protection = $this->aDepartment('Protection Service');
        $ecology = $this->aDepartment('Ecology');
        $ranger = $this->aUser('ranger@example.test', 'Joseph', 'Mollel', $protection);
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $this->anIncident($area, at: $now->modify('-3 days'), reportedBy: $ranger);
        $fine = $this->anIncident($area, 'snaring', 'Snare line lifted', $now->modify('-2 days'));
        new IncidentMoney($fine, MoneyDirectionEnum::Fine)->setAssessed(450_000);
        $this->anIncident($area, 'roadkill', 'Zebra roadkill', new \DateTimeImmutable('2026-07-12 09:00:00'));
        $this->em->flush();

        $protectionKpis = $this->provider()->kpisFor(self::ref($protection, $area), $now);
        $ecologyKpis = $this->provider()->kpisFor(self::ref($ecology, $area), $now);

        self::assertNotSame([], $protectionKpis);
        self::assertEquals($protectionKpis, $ecologyKpis);
    }

    /**
     * "RESOLVED WITHIN TERM" IS NULL — never 0% — while nothing has been resolved.
     */
    public function testTheWithinTermShareIsNullUntilSomethingIsResolved(): void
    {
        $area = $this->anAreaWithKinds();
        $department = $this->aDepartment();
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $this->anIncident($area, at: $now->modify('-1 day'));

        $kpi = $this->kpisFor($department, $now, $area)['incidents_in_term'];
        self::assertFalse($kpi->isKnown(), 'A month with no finished work has no compliance rate.');
        self::assertSame("\u{2014}", $kpi->display());
        self::assertTrue($kpi->isShare());
    }

    public function testResolvedWorkIsScoredAgainstItsOwnCategorysTerm(): void
    {
        $area = $this->anAreaWithKinds();
        $department = $this->aDepartment();
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        // A mortality carries no money, so it walks straight through.
        $incident = $this->anIncident($area, 'natural-mortality', 'Wildebeest carcass', $now->modify('-3 days'));
        $at = $now->modify('-3 days');
        foreach ([IncidentTransitionEnum::Verify, IncidentTransitionEnum::Respond, IncidentTransitionEnum::Resolve] as $step) {
            $this->transitions()->apply($incident, $step, $at = $at->modify('+1 hour'));
        }
        $this->em->flush();

        $kpis = $this->kpisFor($department, $now, $area);
        self::assertSame(1.0, $kpis['incidents_resolved']->value);
        // Resolved in three hours against a 168-hour term: fully within it.
        self::assertSame(100.0, $kpis['incidents_in_term']->value);
    }

    /**
     * MONEY IN TWO PLATES, NEVER ONE. The design refuses to add a fine and a
     * claim together anywhere, and a performance page is not an exception.
     */
    public function testFinesAndCompensationAreTwoPlatesAndAreNeverSummed(): void
    {
        $this->signInAReaderOfMoney();
        $area = $this->anAreaWithKinds();
        $department = $this->aDepartment();
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $claim = $this->anIncident($area, at: $now->modify('-2 days'));
        new IncidentMoney($claim, MoneyDirectionEnum::Compensation)->setApproved(1_200_000);
        $fine = $this->anIncident($area, 'snaring', 'Snare line lifted', $now->modify('-1 day'));
        new IncidentMoney($fine, MoneyDirectionEnum::Fine)->setAssessed(450_000);
        $this->em->flush();

        $kpis = $this->kpisFor($department, $now, $area);
        self::assertSame(450_000.0, $kpis['incidents_fine']->value);
        self::assertSame(1_200_000.0, $kpis['incidents_compensation']->value);
        self::assertSame('TZS', $kpis['incidents_fine']->unit);
    }

    /**
     * A scope with no money of a kind gets NO plate for it — absent, rather than
     * a dashed slot.
     */
    public function testAScopeWithNoMoneyGetsNoMoneyPlates(): void
    {
        $this->signInAReaderOfMoney();
        $area = $this->anAreaWithKinds();
        $department = $this->aDepartment();
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $this->anIncident($area, 'natural-mortality', 'Wildebeest carcass', $now->modify('-1 day'));

        $kpis = $this->kpisFor($department, $now, $area);
        self::assertArrayHasKey('incidents', $kpis);
        self::assertArrayNotHasKey('incidents_fine', $kpis);
        self::assertArrayNotHasKey('incidents_compensation', $kpis);
    }

    /** Last month's figure travels with this month's, for the move the host prints. */
    public function testItCarriesLastMonthForTheMonthOverMonthMove(): void
    {
        $area = $this->anAreaWithKinds();
        $department = $this->aDepartment();
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $this->anIncident($area, at: new \DateTimeImmutable('2026-07-10 09:00:00'));
        $this->anIncident($area, 'snaring', 'Snare line lifted', new \DateTimeImmutable('2026-08-10 09:00:00'));
        $this->anIncident($area, 'bushmeat', 'Bushmeat seized', new \DateTimeImmutable('2026-08-11 09:00:00'));

        $kpi = $this->kpisFor($department, $now, $area)['incidents'];

        self::assertSame(2.0, $kpi->value);
        self::assertSame(1.0, $kpi->previous);
        self::assertSame('+100%', $kpi->deltaLabel());
        self::assertSame('good', $kpi->direction());
    }

    /** Last month alone is still a reading: this month is zero, not absent. */
    public function testLastMonthAloneIsStillAReading(): void
    {
        $area = $this->anAreaWithKinds();
        $department = $this->aDepartment();
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $this->anIncident($area, at: new \DateTimeImmutable('2026-07-10 09:00:00'));

        $kpi = $this->kpisFor($department, $now, $area)['incidents'];
        self::assertSame(0.0, $kpi->value);
        self::assertSame(1.0, $kpi->previous);
    }

    /** An area-scoped ref reads its own area and nothing else. */
    public function testAnAreaScopedRefReportsThatAreaOnly(): void
    {
        $north = $this->anAreaWithKinds('North Sector');
        $south = $this->anAreaWithKinds('South Sector');
        $department = $this->aDepartment();
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $this->anIncident($north, at: $now->modify('-3 days'));
        $this->anIncident($north, 'snaring', 'Snare line lifted', $now->modify('-2 days'));
        $this->anIncident($south, 'roadkill', 'Zebra roadkill', $now->modify('-1 day'));

        self::assertSame(2.0, $this->kpisFor($department, $now, $north)['incidents']->value);
        self::assertSame(1.0, $this->kpisFor($department, $now, $south)['incidents']->value);
    }

    /**
     * AN ORGANIZATION-WIDE REF SUMS EVERY AREA — counts and money alike — into one
     * reading, whoever recorded the rows.
     */
    public function testAnOrganizationWideRefSumsEveryAreaMoneyIncluded(): void
    {
        $this->signInAReaderOfMoney();
        $north = $this->anAreaWithKinds('North Sector');
        $south = $this->anAreaWithKinds('South Sector');
        $department = $this->aDepartment();
        $ecologist = $this->aUser('ecologist@example.test', 'Anna', 'Kileo', $this->aDepartment('Ecology'));
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $first = $this->anIncident($north, 'snaring', 'Snare line lifted', $now->modify('-2 days'), $ecologist);
        new IncidentMoney($first, MoneyDirectionEnum::Fine)->setAssessed(450_000);
        $second = $this->anIncident($south, 'bushmeat', 'Bushmeat seized', $now->modify('-1 day'));
        new IncidentMoney($second, MoneyDirectionEnum::Fine)->setAssessed(50_000);
        $claim = $this->anIncident($south, at: $now->modify('-1 day'));
        new IncidentMoney($claim, MoneyDirectionEnum::Compensation)->setApproved(1_200_000);
        $this->em->flush();

        $kpis = $this->kpisFor($department, $now);
        self::assertSame(3.0, $kpis['incidents']->value);
        self::assertSame(500_000.0, $kpis['incidents_fine']->value);
        self::assertSame(1_200_000.0, $kpis['incidents_compensation']->value);
        self::assertSame(450_000.0, $this->kpisFor($department, $now, $north)['incidents_fine']->value);
    }

    /**
     * THE MONEY PLATES ARE WITHHELD FROM SOMEBODY WHO MAY NOT READ MONEY, and
     * the other three are not. Absent, never a nought and never a dash: a
     * nought is a measurement and a dash means "we could not measure", and
     * this figure was measured and is not theirs.
     */
    public function testTheMoneyPlatesAreWithheldFromAReaderWhoMayNotReadMoney(): void
    {
        $area = $this->anAreaWithKinds();
        $department = $this->aDepartment();
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $fine = $this->anIncident($area, 'snaring', 'Snare line lifted', $now->modify('-1 day'));
        new IncidentMoney($fine, MoneyDirectionEnum::Fine)->setAssessed(450_000);
        $this->em->flush();

        $this->signIn($this->aUser(FixedPermissionVoter::CLERK_EMAIL, 'Sara', 'Mushi'));
        $kpis = $this->kpisFor($department, $now, $area);

        self::assertArrayNotHasKey('incidents_fine', $kpis);
        self::assertArrayNotHasKey('incidents_compensation', $kpis);
        // The work itself is still reported — a withheld fact never withholds
        // the strip it sits on.
        self::assertSame(1.0, $kpis['incidents']->value);
    }

    /**
     * AN ORGANIZATION-WIDE READING NEEDS EVERY AREA. A total across areas is
     * the areas added up, so one area the reader is refused would be smuggled
     * into the figure — and the whole plate goes rather than a quieter total.
     */
    public function testAnOrganizationWideMoneyPlateNeedsEveryAreaInIt(): void
    {
        $north = $this->anAreaWithKinds('North Sector');
        $this->anAreaWithKinds('South Sector');
        $department = $this->aDepartment();
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $fine = $this->anIncident($north, 'snaring', 'Snare line lifted', $now->modify('-1 day'));
        new IncidentMoney($fine, MoneyDirectionEnum::Fine)->setAssessed(450_000);
        $this->em->flush();

        $this->signIn($this->aUser(FixedPermissionVoter::CLERK_EMAIL, 'Sara', 'Mushi'));
        self::assertArrayNotHasKey('incidents_fine', $this->kpisFor($department, $now));
    }

    /**
     * ONE SET OF KPIS PER CALL, whichever ref arrives — a key appears once, never
     * once per area.
     */
    public function testItReturnsExactlyOneSetOfKpisPerCall(): void
    {
        $north = $this->anAreaWithKinds('North Sector');
        $south = $this->anAreaWithKinds('South Sector');
        $department = $this->aDepartment();
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $claim = $this->anIncident($north, at: $now->modify('-2 days'));
        new IncidentMoney($claim, MoneyDirectionEnum::Compensation)->setApproved(1_200_000);
        $fine = $this->anIncident($south, 'snaring', 'Snare line lifted', $now->modify('-1 day'));
        new IncidentMoney($fine, MoneyDirectionEnum::Fine)->setAssessed(450_000);
        $this->em->flush();

        foreach ([null, $north] as $scope) {
            $kpis = $this->provider()->kpisFor(self::ref($department, $scope), $now);
            $keys = array_map(static fn (DepartmentKpi $kpi): string => $kpi->key, $kpis);

            self::assertSame($keys, array_values(array_unique($keys)), 'A key may appear once per call — never once per area.');
        }
    }
}
