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

namespace Uhifadhi\Incident\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentParty;
use Uhifadhi\Incident\Entity\TaxonomyKind;
use Uhifadhi\Incident\Entity\TaxonomySubcategory;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Enum\PartyRoleEnum;
use Uhifadhi\Incident\Model\PartiesOnRecord;
use Uhifadhi\Incident\Model\PartyOnRecord;

/**
 * EVERYBODY ON THE CASE, WHEREVER THEY WERE WRITTEN DOWN. The reporter is a
 * party record from the moment the report is filed; a claimant, a suspect or a
 * witness typed into the report form's Parties block is kept as that block's
 * rows. The case page's Involved parties card reads both, once each.
 */
final class PartiesOnRecordTest extends TestCase
{
    public function testAPartyTypedOnTheReportFormIsOnTheRecordBesideTheReporter(): void
    {
        $incident = $this->incident();
        new IncidentParty($incident, PartyRoleEnum::Reporter, 'Neema Lyimo');
        $incident->setBlockAnswers([BehaviorBlockEnum::Parties->value => ['rows' => [
            ['role' => 'claimant', 'name' => 'Baraka Mollel', 'household' => 'Olkeju boma', 'contact' => '0700 000 000'],
        ]]]);

        $parties = PartiesOnRecord::of($incident);

        self::assertSame(['Neema Lyimo', 'Baraka Mollel'], array_map(static fn (PartyOnRecord $p): string => $p->name, $parties));
        self::assertSame([PartyRoleEnum::Reporter, PartyRoleEnum::Claimant], array_map(static fn (PartyOnRecord $p): PartyRoleEnum => $p->role, $parties));
        self::assertSame('BM', $parties[1]->initials);
        self::assertSame('Olkeju boma', $parties[1]->describedAs);
    }

    /** One person written down twice is one person on the card. */
    public function testARowThatRepeatsAPartyRecordIsNotCountedTwice(): void
    {
        $incident = $this->incident();
        new IncidentParty($incident, PartyRoleEnum::Reporter, 'Neema Lyimo');
        $incident->setBlockAnswers([BehaviorBlockEnum::Parties->value => ['rows' => [
            ['role' => 'reporter', 'name' => 'neema lyimo'],
            ['role' => 'witness', 'name' => 'Salma Kimaro'],
        ]]]);

        self::assertCount(2, PartiesOnRecord::of($incident));
    }

    /** A row with no role the module knows, or no name, is not a party. */
    public function testARowWithoutARoleOrANameIsLeftOut(): void
    {
        $incident = $this->incident();
        $incident->setBlockAnswers([BehaviorBlockEnum::Parties->value => ['rows' => [
            ['role' => 'bystander', 'name' => 'Somebody'],
            ['role' => 'suspect', 'name' => '  '],
        ]]]);

        self::assertSame([], PartiesOnRecord::of($incident));
    }

    private function incident(): Incident
    {
        $area = new AreaOfInterest()->setSource('test fixture');
        $area->setName('Kifaru Sector');
        $kind = new TaxonomyKind($area, 'conflict', 'Human–wildlife conflict');
        $subcategory = new TaxonomySubcategory($kind, 'livestock-depredation', 'livestock depredation');

        return new Incident($area, $subcategory, 'INC-0313', 'Lion killed four goats', '{"type":"Point","coordinates":[-29.55,-3.21]}', new \DateTimeImmutable('2026-08-19 07:10:00'));
    }
}
