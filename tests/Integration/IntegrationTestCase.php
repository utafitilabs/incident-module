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

namespace Uhifadhi\Incident\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\TaxonomySubcategory;
use Uhifadhi\Incident\Tests\Integration\Fixtures\AreaVocabulary;

/**
 * Symfony-standard kernel testing: KernelTestCase + KERNEL_CLASS (phpunit.dist.xml)
 * booting TestKernel with debug=true, so the container self-invalidates when test
 * config changes. It talks to the REAL PostGIS database and rebuilds the schema
 * per test, so every assertion is about what was actually stored — a module whose
 * spatial columns were only ever asserted against a mock would be a module nobody
 * has proved persists.
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        // A SCHEMA IS CREATED FROM AN EMPTY DATABASE, never from whatever the
        // last test left behind. A metadata-driven drop can only drop the
        // tables THIS kernel maps, and a suite before this one may have left
        // others — a table holding a foreign key into one that is mapped
        // blocks the drop, and the create then collides with the table that
        // survived. So the database is taken back to the state a test database
        // starts in: nothing but PostGIS. The extension is recreated with it,
        // because SchemaTool cannot create a `geometry` column in a database
        // that has no such type, and the statement is the core's own first
        // version — what SchemaTool builds on here is what an installation
        // migrates into.
        //
        // @see vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/AreaBundle/tests/Integration/IntegrationTestCase.php
        // @see vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/AreaBundle/migrations/Version20260101000000.php
        $connection = $this->em->getConnection();
        $connection->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');
        $connection->executeStatement('CREATE EXTENSION IF NOT EXISTS postgis');

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($metadata);

        // Anything the boot left managed belongs to the schema that has just
        // been dropped; ids restart at 1 and a stale object would collide with
        // the first row this test writes.
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();

        // The framework's debug error handler is registered during the test and
        // never popped; PHPUnit flags that as risky. Pop whatever is left.
        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    /** An area to file incidents in, with a boundary the map can draw. */
    protected function anArea(string $name = 'Sample Area'): AreaOfInterest
    {
        $area = new AreaOfInterest();
        $area->setName($name);
        // NOT NULL in AreaBundle: an area is always something an
        // installation got from somewhere, and the stub this suite used to map
        // let it be null.
        $area->setSource('test fixture');
        $area->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    /**
     * A zone inside that area — a real polygon, so the point-in-polygon lookup is
     * exercised against PostGIS rather than assumed.
     */
    protected function aZone(AreaOfInterest $area, string $name, float $west = -30.0, float $east = -29.5): Zone
    {
        $zone = new Zone();
        $zone->setArea($area)->setName($name);
        $zone->setGeom(\sprintf(
            '{"type":"MultiPolygon","coordinates":[[[[%1$F,-3.6],[%2$F,-3.6],[%2$F,-2.8],[%1$F,-2.8],[%1$F,-3.6]]]]}',
            $west,
            $east,
        ));
        $this->em->persist($zone);
        $this->em->flush();

        return $zone;
    }

    /**
     * A station inside that area — a real point, so the distance test runs on
     * PostGIS ground rather than on an arithmetic the test did itself.
     */
    protected function aStation(AreaOfInterest $area, string $name, float $longitude, float $latitude = -3.21): Station
    {
        $station = new Station();
        $station->setArea($area)->setName($name);
        $station->setPoint(\sprintf('{"type":"Point","coordinates":[%F,%F]}', $longitude, $latitude));
        $this->em->persist($station);
        $this->em->flush();

        return $station;
    }

    protected function aUser(string $email, string $first = 'J', string $last = 'Mollel', ?Department $department = null): User
    {
        // ONE ROW PER EMAIL. A test that signs the same person in twice — once
        // to take a reading and once to check it — must not try to write them
        // twice; the address is unique in team_user and it is the identity
        // here too.
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $existing) {
            return $existing;
        }

        $user = new User();
        $user->setEmail($email)->setFirstName($first)->setLastName($last);
        // NOT NULL in TeamBundle. Nothing here signs in with a
        // password — the tests use loginUser() and a test header — but a person
        // is a row and the row has to be storable.
        $user->setPassword('not-used-by-these-tests');

        if (null !== $department) {
            /*
             * A DEPARTMENT IS A PLACEMENT, NOT AN OWNER. A position carries no
             * department any more and its name is unique across the whole
             * organization, so the department the caller means is written on
             * the PERSON — across the organization's ground, in that one
             * department — which is what the core's own migration does to
             * everybody who used to hold an area-level department's position.
             */
            $position = new Position();
            $position->setName('Ranger, '.$department->getName());
            $this->em->persist($position);
            $user->setPosition($position);

            $placement = new Placement();
            $placement->acrossTheOrganization()->inDepartments([$department]);
            $this->em->persist($placement);
            $user->setPlacement($placement);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * SIGN SOMEBODY IN, FOR THE DOORS' SAKE.
     *
     * A contributed figure asks `IncidentDoors`, which asks the token storage
     * before it asks the checker — a warm-up, a console command or a reading
     * taken off a request has nobody to ask about and withholds. There is no
     * HTTP client here, so the token goes straight into the storage the way a
     * firewall would have put it there.
     *
     * The account decides what the reading shows: {@see FixedGrantVoter}
     * gives the manager the money and takes it off the clerk.
     */
    protected function signIn(User $user): void
    {
        /** @var TokenStorageInterface $tokens */
        $tokens = static::getContainer()->get('test_public.security.token_storage');
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    protected function aDepartment(string $name = 'Protection Service'): Department
    {
        $department = new Department();
        $department->setName($name);
        $this->em->persist($department);
        $this->em->flush();

        return $department;
    }

    /**
     * AN AREA WITH WORDS TO FILE AGAINST. The module ships no taxonomy and
     * seeds none, so an area is not fileable until somebody has written its
     * kinds — {@see anArea()} is the empty one the kinds editor's own tests
     * need, and this is the one every other test wants.
     *
     * The words are written through the kinds editor's own service, so no test
     * can file against a vocabulary the editor could not have produced. See
     * {@see AreaVocabulary}.
     */
    protected function anAreaWithKinds(string $name = 'Sample Area'): AreaOfInterest
    {
        $area = $this->anArea($name);
        $this->vocabulary()->write($area);
        $this->em->flush();

        return $area;
    }

    protected function subcategory(AreaOfInterest $area, string $code): TaxonomySubcategory
    {
        $subcategory = $this->vocabulary()->subcategory($area, $code);
        self::assertNotNull($subcategory, \sprintf('This area has no sub-category "%s" — were its kinds written?', $code));

        return $subcategory;
    }

    private function vocabulary(): AreaVocabulary
    {
        /** @var AreaVocabulary $vocabulary */
        $vocabulary = static::getContainer()->get(AreaVocabulary::class);

        return $vocabulary;
    }

    /**
     * A filed incident, through the module's own door — never by writing the
     * columns, so the tests can never produce a record the product could not.
     */
    protected function anIncident(
        AreaOfInterest $area,
        string $subcategory = 'livestock-depredation',
        string $title = 'Lion killed four goats at Riverside',
        ?\DateTimeImmutable $at = null,
        ?User $reportedBy = null,
        ?string $position = null,
    ): Incident {
        /** @var \Uhifadhi\Incident\Service\IncidentReportService $reports */
        $reports = static::getContainer()->get('test_public.incident.report');

        return $reports->file(
            area: $area,
            subcategory: $this->subcategory($area, $subcategory),
            title: $title,
            position: $position ?? '{"type":"Point","coordinates":[-29.75,-3.21]}',
            now: $at ?? new \DateTimeImmutable('2026-08-20 05:41:00'),
            reportedBy: $reportedBy,
        );
    }

    /** Fetch a private bundle service through the test kernel's public aliases. */
    protected function service(string $id): object
    {
        return static::getContainer()->get('test_public.'.$id);
    }
}
