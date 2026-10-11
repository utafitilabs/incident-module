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

namespace Uhifadhi\Incident\Tests\Integration\Facts;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\Service\FactRebuildService;
use Uhifadhi\Contracts\Facts\Fact;
use Uhifadhi\Contracts\Facts\FactPeriod;
use Uhifadhi\Contracts\Facts\FactProviderInterface;
use Uhifadhi\Contracts\Facts\FactReaderInterface;
use Uhifadhi\Contracts\Facts\FactSubject;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Facts\IncidentFactProvider;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * THE INCIDENT FIGURES THE WORKER FILES ON THE FACTS LEDGER, per area and per
 * month, so the performance page reads stored rows instead of loading every
 * incident of every period on every request.
 *
 * Filed through the core's own rebuild, on the real database, and read back as
 * a page reads them.
 */
final class IncidentFactProviderTest extends IntegrationTestCase
{
    private const string AUGUST = '2026-08';

    private AreaOfInterest $north;
    private AreaOfInterest $south;

    protected function setUp(): void
    {
        parent::setUp();

        $this->north = $this->anAreaWithKinds('North Sector');
        $this->south = $this->anAreaWithKinds('South Sector');

        $this->anIncident($this->north, at: new \DateTimeImmutable('2026-08-04 09:00:00'));
        $this->anIncident($this->north, 'snaring', 'Snare line lifted', new \DateTimeImmutable('2026-08-06 09:00:00'))
            ->setStatus(IncidentStatusEnum::Resolved)
            ->setResolvedAt(new \DateTimeImmutable('2026-08-08 09:00:00'));
        $this->anIncident($this->south, 'roadkill', 'Zebra roadkill', new \DateTimeImmutable('2026-08-07 09:00:00'));
        $this->anIncident($this->south, 'roadkill', 'Impala roadkill', new \DateTimeImmutable('2026-09-02 09:00:00'));
        $this->em->flush();
    }

    public function testItIsTaggedForTheCoresScheduleAndDeclaresEachFigureOnce(): void
    {
        $provider = $this->service('incident.facts');
        self::assertInstanceOf(FactProviderInterface::class, $provider);
        self::assertSame('incidents', $provider->moduleSlug());

        $declared = [];
        foreach ($provider->figures() as $figure) {
            $declared[$figure->key] = [$figure->subjectKind, $figure->additive];
        }

        self::assertSame([
            IncidentFactProvider::AREA_FILED => [FactSubject::AREA, true],
            IncidentFactProvider::AREA_RESOLVED => [FactSubject::AREA, true],
            IncidentFactProvider::AREA_CLAIMS_FILED => [FactSubject::AREA, true],
            IncidentFactProvider::AREA_OPEN_PAST_TARGET => [FactSubject::AREA, false],
            IncidentFactProvider::AREA_MEDIAN_DAYS_TO_CLOSE => [FactSubject::AREA, false],
            IncidentFactProvider::AREA_OPEN_AGED_7 => [FactSubject::AREA, false],
            IncidentFactProvider::AREA_OPEN_AGED_14 => [FactSubject::AREA, false],
            IncidentFactProvider::AREA_OPEN_AGED_21 => [FactSubject::AREA, false],
            IncidentFactProvider::AREA_OPEN_AGED_OVER_21 => [FactSubject::AREA, false],
            IncidentFactProvider::INSTALLATION_MEDIAN_DAYS_TO_CLOSE => [FactSubject::INSTALLATION, false],
        ], $declared);
    }

    public function testAMonthsFlowIsFiledPerArea(): void
    {
        $this->fileAugust();

        $north = $this->areaFacts($this->north);
        self::assertSame(2.0, $north[IncidentFactProvider::AREA_FILED]->value);
        self::assertSame(1.0, $north[IncidentFactProvider::AREA_RESOLVED]->value);
        self::assertSame(1.0, $north[IncidentFactProvider::AREA_CLAIMS_FILED]->value, 'livestock depredation is a compensation claim');
        self::assertSame(2.0, $north[IncidentFactProvider::AREA_MEDIAN_DAYS_TO_CLOSE]->value);

        $south = $this->areaFacts($this->south);
        self::assertSame(1.0, $south[IncidentFactProvider::AREA_FILED]->value, 'September’s is September’s');
        self::assertSame(0.0, $south[IncidentFactProvider::AREA_RESOLVED]->value);
        self::assertFalse($south[IncidentFactProvider::AREA_MEDIAN_DAYS_TO_CLOSE]->isKnown(), 'a median of no finished work is no figure');
    }

    /** What is still open is counted at the month's end: past its target, and by how long it has been open. */
    public function testTheOpenWorkIsCountedAtTheMonthsEnd(): void
    {
        $this->fileAugust();

        $north = $this->areaFacts($this->north);
        self::assertSame(0.0, $north[IncidentFactProvider::AREA_OPEN_PAST_TARGET]->value, 'a depredation claim is promised 30 days, and 27 have passed');
        self::assertSame(1.0, $this->areaFacts($this->south)[IncidentFactProvider::AREA_OPEN_PAST_TARGET]->value, 'a roadkill is promised 7, and 24 have passed');
        self::assertSame(
            [0.0, 0.0, 0.0, 1.0],
            [
                $north[IncidentFactProvider::AREA_OPEN_AGED_7]->value,
                $north[IncidentFactProvider::AREA_OPEN_AGED_14]->value,
                $north[IncidentFactProvider::AREA_OPEN_AGED_21]->value,
                $north[IncidentFactProvider::AREA_OPEN_AGED_OVER_21]->value,
            ],
            'open since the 4th, so 27 days at the month’s end',
        );
    }

    /** The organization's median is the middle of all its finished work, not a middle of the areas' middles. */
    public function testTheOrganizationsMedianIsFiledForTheInstallation(): void
    {
        $this->fileAugust();

        $organization = $this->facts()->latest(FactSubject::INSTALLATION, FactSubject::INSTALLATION_UUID, IncidentFactProvider::INSTALLATION_MEDIAN_DAYS_TO_CLOSE, self::AUGUST);
        self::assertNotNull($organization);
        self::assertSame(2.0, $organization->value);
    }

    /** An area nothing was filed in filed nothing: noughts, filed, never a hole. */
    public function testAnAreaWithNothingFiledFilesNoughts(): void
    {
        $quiet = $this->anAreaWithKinds('Quiet Sector');
        $this->fileAugust();

        $facts = $this->areaFacts($quiet);
        self::assertSame(0.0, $facts[IncidentFactProvider::AREA_FILED]->value);
        self::assertSame(0.0, $facts[IncidentFactProvider::AREA_OPEN_PAST_TARGET]->value);
    }

    private function fileAugust(): void
    {
        $rebuild = $this->service('registry.facts.rebuild');
        self::assertInstanceOf(FactRebuildService::class, $rebuild);
        $rebuild->rebuild([FactPeriod::fromKey(self::AUGUST)], 'incidents');
    }

    /** @return array<string, Fact> */
    private function areaFacts(AreaOfInterest $area): array
    {
        $uuid = (string) $area->getUuidString();

        return $this->facts()->batch(FactSubject::AREA, [$uuid], IncidentFactProvider::AREA_FIGURES, self::AUGUST)[$uuid] ?? [];
    }

    private function facts(): FactReaderInterface
    {
        $reader = $this->service('registry.facts.reader');
        self::assertInstanceOf(FactReaderInterface::class, $reader);

        return $reader;
    }
}
