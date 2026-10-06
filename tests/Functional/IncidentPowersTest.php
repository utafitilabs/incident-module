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

namespace Uhifadhi\Incident\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\CellAnswer;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionEvaluator;
use Uhifadhi\Bundle\TeamBundle\Permissions\PowerCatalogue;
use Uhifadhi\Contracts\Access\Power;
use Uhifadhi\Contracts\Access\PowerGroup;
use Uhifadhi\Contracts\Access\PowerTarget;
use Uhifadhi\Contracts\Access\WholeOrganization;
use Uhifadhi\Incident\Access\IncidentPowers;
use Uhifadhi\Incident\Service\IncidentReportService;
use Uhifadhi\Incident\Service\TaxonomyAdminService;
use Uhifadhi\Incident\Tests\Functional\Authority\AuthorityKernel;
use Uhifadhi\Test\Authority\World;

/**
 * SOMEBODY ELSE'S INCIDENT, ON TEAM › PERMISSIONS. Moving a case on, settling
 * its money, attaching evidence to it and taking evidence off it are each a
 * power over the person who filed it, and the page asks them through the
 * core's own evaluator, as real accounts, against the core's own voters.
 *
 * The world is the authority table's: the member at Kilimani and the person
 * out of reach at Tambarare have each filed one incident in their own area.
 */
final class IncidentPowersTest extends KernelTestCase
{
    private const array KEYS = [
        'move-somebody-elses-incident',
        'settle-the-money-on-somebody-elses-incident',
        'attach-evidence-to-somebody-elses-incident',
        'take-evidence-off-somebody-elses-incident',
    ];

    private EntityManagerInterface $em;
    private World $world;

    protected static function getKernelClass(): string
    {
        return AuthorityKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $connection = $em->getConnection();
        $connection->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');
        $connection->executeStatement('CREATE EXTENSION IF NOT EXISTS postgis');
        new SchemaTool($em)->createSchema($em->getMetadataFactory()->getAllMetadata());

        $catalogue = self::getContainer()->get('team.access.catalogue');
        self::assertInstanceOf(ConcernCatalogue::class, $catalogue);
        $this->world = World::seed($em, $catalogue->exceptionPairs()[0] ?? '');
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();

        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    public function testEachIsDeclaredAsAPowerOverSomebodyElsesRecords(): void
    {
        $declared = [];
        foreach ($this->catalogue()->all() as $power) {
            if (\in_array($power->key, self::KEYS, true)) {
                $declared[$power->key] = $power;
            }
        }

        self::assertSame(self::KEYS, array_keys($declared));
        foreach ($declared as $power) {
            self::assertSame(PowerGroup::OthersRecords, $power->group);
            self::assertSame([PowerTarget::AColleague, PowerTarget::SomebodyBeyondTheirArea], $power->targets);
            self::assertInstanceOf(IncidentPowers::class, $this->catalogue()->sourceOf($power));
        }
    }

    public function testSomebodyHoldingThePairsAtKilimaniMayChangeTheIncidentsOfAColleagueThereAndOfNobodyBeyond(): void
    {
        $this->fileOneEach();
        $actor = $this->staff('Kilimani Manager', ['incidents.manage', 'case-money.manage', 'case-files.manage', 'case-files.delete'], $this->world->kilimani);

        self::assertSame(
            array_fill_keys(self::KEYS, ['a-colleague' => 'allowed', 'somebody-beyond-their-area' => 'refused']),
            $this->answersFor($actor),
        );
    }

    public function testSomebodyWithoutThePairsMayChangeNobodysIncidents(): void
    {
        $this->fileOneEach();
        $actor = $this->staff('Kilimani Reader', ['incidents.read'], $this->world->kilimani);

        self::assertSame(
            array_fill_keys(self::KEYS, ['a-colleague' => 'refused', 'somebody-beyond-their-area' => 'refused']),
            $this->answersFor($actor),
        );
    }

    /** An Admin reaches everybody, so nobody is beyond their area to ask about. */
    public function testAnAdminMayChangeAColleaguesIncidents(): void
    {
        $this->fileOneEach();
        $admin = $this->em->getRepository(User::class)->findOneBy(['uuid' => $this->world->admin]);
        self::assertInstanceOf(User::class, $admin);

        self::assertSame(
            array_fill_keys(self::KEYS, ['a-colleague' => 'allowed', 'somebody-beyond-their-area' => 'no target']),
            $this->answersFor($admin),
        );
    }

    /** The question is asked of the area their incident lies in. */
    public function testTheQuestionIsAskedOfTheAreaTheirLatestIncidentLiesIn(): void
    {
        $this->fileOneEach();
        $member = $this->person($this->world->member);

        $subject = $this->source()->subject($this->power('move-somebody-elses-incident'), 'incidents.manage', PowerTarget::AColleague, $member);

        self::assertInstanceOf(AreaOfInterest::class, $subject);
        self::assertSame($this->world->kilimani, $subject->getUuidString());
    }

    /**
     * SOMEBODY WHO HAS FILED NOTHING YET is asked about where their next case
     * would be filed: the one area they are placed at, or — placed across
     * several, across the organization or nowhere — anywhere at all.
     */
    public function testSomebodyWhoHasFiledNothingIsAskedAboutTheGroundTheyArePlacedOn(): void
    {
        $power = $this->power('move-somebody-elses-incident');

        $atKilimani = $this->source()->subject($power, 'incidents.manage', PowerTarget::AColleague, $this->person($this->world->member));
        self::assertInstanceOf(AreaOfInterest::class, $atKilimani);
        self::assertSame($this->world->kilimani, $atKilimani->getUuidString());

        $placedNowhere = $this->em->getRepository(User::class)->findOneBy(['email' => 'rehema.kimaro@unr.example']);
        self::assertInstanceOf(User::class, $placedNowhere);
        self::assertInstanceOf(WholeOrganization::class, $this->source()->subject($power, 'incidents.manage', PowerTarget::SomebodyBeyondTheirArea, $placedNowhere));
    }

    /** @return array<string, array<string, string>> per power, per target, the answer */
    private function answersFor(User $actor): array
    {
        $evaluator = self::getContainer()->get('team.permissions.evaluator');
        self::assertInstanceOf(PermissionEvaluator::class, $evaluator);

        $answers = [];
        foreach ($evaluator->forPerson($actor) as $cell) {
            if (\in_array($cell->power->key, self::KEYS, true)) {
                $answers[$cell->power->key][$cell->kind->value] = match ($cell->answer) {
                    CellAnswer::Allowed => 'allowed',
                    CellAnswer::Refused => 'refused',
                    CellAnswer::NoTarget => 'no target',
                };
            }
        }

        return $answers;
    }

    /** One incident each, in their own area: the member's at Kilimani, the other's at Tambarare. */
    private function fileOneEach(): void
    {
        $admin = self::getContainer()->get('incident.taxonomy_admin');
        $reports = self::getContainer()->get('incident.report');
        self::assertInstanceOf(TaxonomyAdminService::class, $admin);
        self::assertInstanceOf(IncidentReportService::class, $reports);

        foreach ([[$this->world->kilimani, $this->world->member], [$this->world->tambarare, $this->world->outOfReach]] as [$area, $person]) {
            $ground = $this->em->getRepository(AreaOfInterest::class)->findOneBy(['uuid' => $area]);
            self::assertInstanceOf(AreaOfInterest::class, $ground);
            $subcategory = $admin->createSubcategory($admin->createKind($ground, 'Conflict', 'conflict'), 'crop raiding', 'crop-raiding');
            $this->em->flush();

            $reports->file(
                area: $ground,
                subcategory: $subcategory,
                title: 'Elephants in the maize',
                position: '{"type":"Point","coordinates":[37.1,-2.8]}',
                now: new \DateTimeImmutable('-1 day'),
                reportedBy: $this->person($person),
            );
        }
    }

    /**
     * Staff seated in a position of these pairs and placed at one area, in
     * Operations, as the authority table's senders are.
     *
     * @param list<string> $pairs
     */
    private function staff(string $name, array $pairs, string $area): User
    {
        $ground = $this->em->getRepository(AreaOfInterest::class)->findOneBy(['uuid' => $area]);
        $operations = $this->em->find(Department::class, $this->world->operations);
        self::assertInstanceOf(AreaOfInterest::class, $ground);
        self::assertInstanceOf(Department::class, $operations);

        $catalogue = self::getContainer()->get('team.access.catalogue');
        self::assertInstanceOf(ConcernCatalogue::class, $catalogue);
        $position = new Position()->setName($name)->setGrantValues($pairs, $catalogue->positionPairs());
        $placement = new Placement()->inAreas([$ground])->inDepartment($operations);
        [$first, $last] = explode(' ', $name, 2);
        $user = new User()->setEmail(strtolower(str_replace(' ', '.', $name)).'@unr.example')
            ->setFirstName($first)->setLastName($last)->setPassword('a hash, never a password')
            ->setTeamRole(TeamRoleEnum::Staff)->setVerified(true)
            ->setPosition($position)->setPlacement($placement);

        $this->em->persist($position);
        $this->em->persist($placement);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function person(string $uuid): User
    {
        $person = $this->em->getRepository(User::class)->findOneBy(['uuid' => $uuid]);
        self::assertInstanceOf(User::class, $person);

        return $person;
    }

    private function power(string $key): Power
    {
        foreach ($this->catalogue()->all() as $power) {
            if ($key === $power->key) {
                return $power;
            }
        }

        self::fail('No power '.$key.' is declared.');
    }

    private function source(): IncidentPowers
    {
        $source = $this->catalogue()->sourceOf($this->power('move-somebody-elses-incident'));
        self::assertInstanceOf(IncidentPowers::class, $source);

        return $source;
    }

    private function catalogue(): PowerCatalogue
    {
        $powers = self::getContainer()->get('team.permissions.powers');
        self::assertInstanceOf(PowerCatalogue::class, $powers);

        return $powers;
    }
}
