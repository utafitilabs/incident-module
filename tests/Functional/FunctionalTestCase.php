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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Service\RegistrySyncService;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Incident\Devkit\DemoMonth;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\TaxonomySubcategory;
use Uhifadhi\Incident\Enum\IncidentSeverityEnum;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Service\IncidentReportService;
use Uhifadhi\Incident\Tests\Integration\Fixtures\AreaVocabulary;
use Uhifadhi\Incident\Tests\Integration\Fixtures\FixedGrantVoter;

/**
 * THE SCREENS, THROUGH A REAL KERNEL. Every page below is fetched over HTTP
 * against a real PostGIS database with real security — the only way to prove a
 * module bundle's routes, templates and permissions actually work when installed,
 * rather than that its services return the right arrays.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
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

        // THE HALF A DEPLOY ALREADY DOES. In an installation `registry:sync`
        // fills the catalogue after the migrations, so it holds this module
        // before the first request; here the schema is rebuilt after the kernel
        // booted, so the reconciliation is run by hand. Without it the catalogue is empty,
        // `install()` below has no row to point at, and the gate lets everything
        // through — a suite that would pass while every page 404'd in the park.
        /** @var RegistrySyncService $registry */
        $registry = static::getContainer()->get('test_public.registry.sync');
        $registry->sync();
        $this->em->clear();
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

    /**
     * The area's UUID as a route needs it. The host entity's getter is nullable
     * (an unsaved area has none); everything here is persisted, so this is the
     * one place that says so.
     */
    protected function uuidOf(AreaOfInterest $area): string
    {
        $uuid = $area->getUuidString();
        self::assertNotNull($uuid, 'A persisted area always has a uuid.');

        return $uuid;
    }

    /**
     * An area this module is NOT switched on for — which is the state every area
     * is in until an admin says otherwise. Every page this module ships answers
     * 404 there, and that is the registry's doing rather than this module's.
     */
    protected function anAreaWithoutTheModule(string $name = 'Unopened Reserve'): AreaOfInterest
    {
        return $this->newArea($name);
    }

    /**
     * An area with this module switched on — which is what a functional test
     * about an incident screen needs, because an area written straight into the
     * database is running nothing and every page below answers 404, correctly
     * and uselessly.
     *
     * It is switched on the way an ADMIN switches it on: through the registry's
     * own service, against the catalogue row the sync wrote from this module's
     * provider. That is the one half of installing a module no warm-up will ever
     * do, because it is exactly the decision the sync refuses to overrule.
     */
    protected function anArea(string $name = 'Sample Area'): AreaOfInterest
    {
        $area = $this->newArea($name);

        /** @var AreaModuleService $areaModules */
        $areaModules = static::getContainer()->get('test_public.registry.area_modules');
        $areaModules->install($area, IncidentModuleProvider::SLUG);

        return $area;
    }

    private function newArea(string $name): AreaOfInterest
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

    protected function aZone(AreaOfInterest $area, string $name): Zone
    {
        $zone = new Zone();
        $zone->setArea($area)->setName($name);
        $zone->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.5,-3.6],[-29.5,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}');
        $this->em->persist($zone);
        $this->em->flush();

        return $zone;
    }

    /** Somebody who may FILE and may not MOVE — the cheap half of the workflow. */
    protected function aReporter(): User
    {
        return $this->aUser(FixedGrantVoter::REPORTER_EMAIL, 'Joseph', 'Mollel');
    }

    /** Somebody who may do both — the supervisor. */
    protected function aManager(): User
    {
        return $this->aUser(FixedGrantVoter::MANAGER_EMAIL, 'Sara', 'Laizer');
    }

    protected function aUser(string $email, string $first, string $last): User
    {
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
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * AN AREA WITH THE MODULE ON AND WORDS TO FILE AGAINST. The module ships
     * no taxonomy and seeds none, so an area is not fileable until somebody
     * has written its kinds — {@see anArea()} is the empty one the kinds
     * editor's own tests need, and this is the one every other test wants.
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

    protected function anIncident(
        AreaOfInterest $area,
        string $subcategory = 'livestock-depredation',
        string $title = 'Lion killed four goats at Riverside',
        ?User $reportedBy = null,
        ?IncidentSeverityEnum $severity = null,
    ): Incident {
        /** @var IncidentReportService $reports */
        $reports = static::getContainer()->get('test_public.incident.report');

        $word = $this->subcategory($area, $subcategory);

        return $reports->file(
            area: $area,
            subcategory: $word,
            title: $title,
            position: '{"type":"Point","coordinates":[-29.75,-3.21]}',
            now: new \DateTimeImmutable(),
            severity: $severity ?? IncidentSeverityEnum::Moderate,
            reportedBy: $reportedBy,
            // ANSWERED THE WAY THE FORM WOULD HAVE BEEN: the demo's own answers to
            // the blocks this word switched on, so a page under test is reading a
            // record a filer could actually have produced.
            blockAnswers: DemoMonth::blockAnswersFor($word, 0),
        );
    }

    /**
     * The CSRF token for one incident's transitions, minted the way the page
     * mints it.
     *
     * Scraped off the RENDERED SURFACE, not minted from the token manager. That is
     * the point: it proves a page actually carries a token a client could use. A
     * test that minted its own would have passed while the status board posted
     * nothing and 403'd in every real browser.
     *
     * It is read from the `.i-trans` row rather than from a form's hidden input
     * because the form is not always there — a case file with no legal move
     * renders no button, and those are exactly the states whose refusals are most
     * worth testing.
     */
    protected function csrfFor(AreaOfInterest $area): string
    {
        $html = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)))->html();
        preg_match('/data-incident-csrf="([^"]+)"/', $html, $matches);
        if (!isset($matches[1])) {
            self::fail('No surface rendered a transition token, so its board could never post one.');
        }

        return $matches[1];
    }

    /** The CSRF token a page rendered, read back out of it. */
    protected function tokenFrom(string $html, string $name = '_token'): string
    {
        preg_match(\sprintf('/name="%s" value="([^"]+)"/', preg_quote($name, '/')), $html, $matches);
        if (!isset($matches[1])) {
            self::fail('The page rendered no CSRF token, so its form could never be submitted.');
        }

        return $matches[1];
    }
}
