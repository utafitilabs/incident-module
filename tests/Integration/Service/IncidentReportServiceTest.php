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

namespace Uhifadhi\Incident\Tests\Integration\Service;

use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingChange;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingsWriter;
use Uhifadhi\Contracts\Settings\SettingDepth;
use Uhifadhi\Incident\Enum\IncidentSourceEnum;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Model\BlockAnswers;
use Uhifadhi\Incident\Model\IncidentPrefill;
use Uhifadhi\Incident\Service\IncidentReportService;
use Uhifadhi\Incident\Settings\PlacementSettings;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * FILING, against a real database and a real PostGIS.
 *
 * The two facts that cannot be added later without lying are the ones asserted
 * hardest here: WHERE it happened (a real point, in a real zone) and WHAT IT CAME
 * FROM (provenance, written once).
 */
final class IncidentReportServiceTest extends IntegrationTestCase
{
    private function reports(): IncidentReportService
    {
        /** @var IncidentReportService $reports */
        $reports = $this->service('incident.report');

        return $reports;
    }

    protected function setUp(): void
    {
        parent::setUp();
    }

    /** EVERY source lands on `reported`. There is no way to file something verified. */
    public function testAFiledIncidentStartsAtReported(): void
    {
        $incident = $this->anIncident($this->anAreaWithKinds());

        self::assertSame(IncidentStatusEnum::Reported, $incident->getStatus());
        self::assertSame('INC-0001', $incident->getReference());
        // The first event IS the filing: a record whose history begins after it
        // was created cannot say who started it.
        self::assertCount(1, $incident->getEvents());
    }

    /** Case numbers are issued in order and never reused. */
    public function testCaseNumbersRunInOrder(): void
    {
        $area = $this->anAreaWithKinds();

        self::assertSame('INC-0001', $this->anIncident($area)->getReference());
        self::assertSame('INC-0002', $this->anIncident($area)->getReference());
        self::assertSame('INC-0003', $this->anIncident($area)->getReference());
    }

    /**
     * THE POINT IS RESOLVED TO A ZONE, in PostGIS, once — at filing. An incident
     * does not move, so recomputing it per read would be a spatial join behind
     * every widget.
     */
    public function testTheZoneIsResolvedFromTheRealGeometry(): void
    {
        $area = $this->anAreaWithKinds();
        $west = $this->aZone($area, 'North Gate', -30.0, -29.5);
        $this->aZone($area, 'South Gate', -29.5, -29.0);

        // -29.75 is inside North Gate and outside South Gate.
        $incident = $this->anIncident($area);

        self::assertSame($west->getId(), $incident->getZone()?->getId());
        self::assertSame('North Gate', $incident->zoneLabel());
    }

    /**
     * OUTSIDE THE AREA IS A MARK, AND FILING NEVER REFUSES IT: a report from a
     * phone reaches the service directly, and an area set to Refuse still keeps
     * it, marked outside the area.
     */
    public function testAPointOutsideTheBoundaryIsFiledAndMarkedWhateverTheSetting(): void
    {
        $area = $this->anAreaWithKinds();
        /** @var SettingsWriter $writer */
        $writer = $this->service('registry.settings.writer');
        $writer->apply([new SettingChange(
            PlacementSettings::OUTSIDE_BOUNDARY,
            SettingDepth::Area,
            (string) $area->getUuidString(),
            'refuse',
        )], 'test');

        $outside = $this->anIncident($area, position: '{"type":"Point","coordinates":[-28.989,-3.21]}');
        $inside = $this->anIncident($area);

        self::assertTrue($outside->isOutsideArea());
        self::assertFalse($inside->isOutsideArea());
    }

    /** UNZONED IS A FIRST-CLASS ANSWER — an org with no zones is the normal state. */
    public function testAnAreaWithNoZonesFilesPerfectlyWell(): void
    {
        $incident = $this->anIncident($this->anAreaWithKinds());

        self::assertNull($incident->getZone());
        self::assertSame('unzoned', $incident->zoneLabel());
    }

    /**
     * FILING OPENS NO MONEY RECORD, whatever the category.
     *
     * A sub-category that CARRIES money is one whose form offers the fields; it
     * is not a promise that this incident involves any. The row appears when
     * somebody records an amount — which is also when the case file's money card
     * appears, and why a roadkill with no fine can still be resolved.
     */
    public function testFilingOpensNoMoneyRecord(): void
    {
        $area = $this->anAreaWithKinds();

        $conflict = $this->anIncident($area, 'livestock-depredation');
        self::assertNull($conflict->getMoney());
        // …but the FORM will offer the fields, because the category carries them.
        self::assertTrue($conflict->carriesMoney());
        self::assertSame(MoneyDirectionEnum::Compensation, $conflict->getSubcategory()->getMoneyDirection());

        $mortality = $this->anIncident($area, 'natural-mortality', 'Wildebeest carcass, no injury pattern');
        self::assertNull($mortality->getMoney());
        self::assertFalse($mortality->carriesMoney());
    }

    /** Roadkill is the ruling made real: one entry, and it MAY carry a FINE. */
    public function testRoadkillMayCarryAFineRatherThanBeingTwoLinkedIncidents(): void
    {
        $roadkill = $this->anIncident($this->anAreaWithKinds(), 'roadkill', 'Zebra roadkill on the C-road, km 12');

        self::assertTrue($roadkill->carriesMoney());
        self::assertSame(MoneyDirectionEnum::Fine, $roadkill->getSubcategory()->getMoneyDirection());
        // Nobody has been fined, so there is nothing on the record — and that is
        // an incident that can be resolved.
        self::assertNull($roadkill->getMoney());
    }

    /**
     * PROVENANCE IS WRITTEN ONCE. An incident filed from a patrol observation
     * stays linked to it forever, and nothing can rewrite it.
     */
    public function testAnIncidentFiledFromAnObservationKeepsThatLinkForever(): void
    {
        $observation = Uuid::v7();
        $area = $this->anAreaWithKinds();
        $incident = $this->reports()->file(
            area: $area,
            subcategory: $this->subcategory($area, 'livestock-depredation'),
            title: 'Fresh lion tracks 400 m from North Gate bomas',
            position: '{"type":"Point","coordinates":[-29.75,-3.21]}',
            now: new \DateTimeImmutable('2026-08-22 08:15:00'),
            source: IncidentSourceEnum::PatrolObservation,
            prefill: new IncidentPrefill(
                record: $observation,
                label: 'observation 2 of patrol P-0142',
                backUrl: '/areas/x/modules/patrols/observation/2',
            ),
        );

        self::assertTrue($incident->hasProvenance());
        self::assertSame($observation->toRfc4122(), $incident->getSourceRecordUuid()?->toRfc4122());
        self::assertSame('observation 2 of patrol P-0142', $incident->getSourceRecordLabel());

        $this->expectException(\LogicException::class);
        $incident->recordProvenance(Uuid::v7(), 'somewhere else entirely');
    }

    /**
     * A COMMUNITY REPORT IS AN ORDINARY REPORTED INCIDENT WEARING A BADGE. The
     * ruling, as a stored row: no sixth place, and no permission changes.
     */
    public function testAnSmsFromAVillageEntersLikeEverythingElse(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->reports()->file(
            area: $area,
            subcategory: $this->subcategory($area, 'crop-raiding'),
            title: 'Elephants in the gardens at Spring Basin',
            position: '{"type":"Point","coordinates":[-29.75,-3.21]}',
            now: new \DateTimeImmutable('2026-08-22 06:00:00'),
            source: IncidentSourceEnum::Sms,
        );

        self::assertSame(IncidentStatusEnum::Reported, $incident->getStatus());
        self::assertSame(IncidentSourceEnum::Sms, $incident->getSource());
        self::assertFalse($incident->getSource()->isFirstParty());
        self::assertSame('SMS', $incident->getSource()->badge());
    }

    /**
     * THE ANSWERS ARE KEPT IN THE SHAPE THE BLOCKS ASK IN, and the figure asked at
     * filing is kept apart from the money record — which is not opened here at
     * all, whatever was claimed.
     */
    public function testTheBlockAnswersAndTheClaimedFigureAreStoredAsFiled(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->reports()->file(
            area: $area,
            subcategory: $this->subcategory($area, 'roadkill'),
            title: 'Zebra roadkill on a district road',
            position: '{"type":"Point","coordinates":[-29.75,-3.21]}',
            now: new \DateTimeImmutable('2026-08-21 16:20:00'),
            blockAnswers: new BlockAnswers([
                'species' => ['species' => 'Plains zebra', 'sex' => 'female'],
                'named-place' => ['place_kind' => 'road segment', 'place_name' => 'A district road, km 12'],
            ], 200_000),
        );

        self::assertSame([
            'species' => ['species' => 'Plains zebra', 'sex' => 'female'],
            'named-place' => ['place_kind' => 'road segment', 'place_name' => 'A district road, km 12'],
        ], $incident->getBlockAnswers());
        self::assertSame(200_000, $incident->getClaimedAtFiling());
        self::assertNull($incident->getMoney());
    }

    /** A filing that answered no block at all carries an empty shape, never a null. */
    public function testAFilingWithNoBlockAnswersCarriesAnEmptyShape(): void
    {
        $incident = $this->anIncident($this->anAreaWithKinds());

        self::assertSame([], $incident->getBlockAnswers());
        self::assertNull($incident->getClaimedAtFiling());
    }

    /** The person who filed it is a party to it, in the reporter's role. */
    public function testTheFilerIsNamedAsAPartyToTheirOwnReport(): void
    {
        $user = $this->aUser('ranger@example.test', 'Joseph', 'Mollel');
        $incident = $this->anIncident($this->anAreaWithKinds(), reportedBy: $user);

        $parties = $incident->getParties()->toArray();
        self::assertCount(1, $parties);
        self::assertSame('J. Mollel', $parties[0]->getName());
        self::assertSame('reporter', $parties[0]->getRole()->value);
    }
}
