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

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Incident\Access\IncidentConcerns;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Tests\Integration\Fixtures\CollectedKpiProviders;
use Uhifadhi\Incident\Tests\Integration\Fixtures\CollectedModules;
use Uhifadhi\Incident\Tests\Integration\Fixtures\CollectedStationFigureProviders;
use Uhifadhi\Incident\Tests\Integration\Fixtures\CollectedZoneFigureProviders;

/**
 * The host contract: installing this bundle puts "incidents" in the catalogue.
 * A reusable bundle is not autoconfigured, so the "uhifadhi.module" tag is
 * applied by hand in the extension — this test is what proves it stuck.
 */
final class ModuleRegistrationTest extends KernelTestCase
{
    public function testTheIncidentsModuleReachesTheRegistrysCatalogue(): void
    {
        self::bootKernel();

        /** @var CollectedModules $catalogue */
        $catalogue = self::getContainer()->get(CollectedModules::class);
        $modules = $catalogue->bySlug();

        self::assertArrayHasKey('incidents', $modules);
        self::assertInstanceOf(IncidentModuleProvider::class, $modules['incidents']);
        self::assertSame('Incidents', $modules['incidents']->name());
        self::assertSame('incident:triangle-alert', $modules['incidents']->icon());
        // It owns its screens, so the host's tile links straight to them.
        self::assertSame('incident_dashboard', $modules['incidents']->entryRoute());
    }

    /**
     * THE ACCESS SEAM. The tag is applied BY HAND in the extension (a reusable
     * bundle is not autoconfigured), and a module that forgot it would have
     * every one of its gates refuse — the voter only recognises a pair the
     * catalogue knows — which reads exactly like a permission nobody granted.
     *
     * Asked of the CORE's own catalogue rather than of the declaration, so
     * what is proved is that the tagged source reached it.
     */
    public function testItsConcernsReachTheCoresGrantsMatrix(): void
    {
        self::bootKernel();

        /** @var ConcernCatalogue $catalogue */
        $catalogue = self::getContainer()->get('test_public.team.access.catalogue');

        $mine = [];
        foreach ($catalogue->pairs() as $pair) {
            $concern = Grant::parse((string) $pair)->concern;
            if (IncidentModuleProvider::SLUG === $catalogue->moduleOf($concern)) {
                $mine[] = (string) $pair;
            }
        }

        self::assertSame([
            'incidents.read',
            'incidents.record',
            'incidents.manage',
            'incidents.export',
            'incident-vocabulary.read',
            'incident-vocabulary.configure',
            'case-files.read',
            'case-files.manage',
            'case-files.delete',
            'case-money.read',
            'case-money.manage',
        ], $mine);

        // A FACT ABOUT A PERSON OR A CASE IS DECLARED SENSITIVE, so an
        // organization can withhold it without withholding the page it sits on.
        self::assertTrue($catalogue->isSensitive(IncidentConcerns::CASE_FILES));
        self::assertTrue($catalogue->isSensitive(IncidentConcerns::CASE_MONEY));
        self::assertFalse($catalogue->isSensitive(IncidentConcerns::INCIDENTS));
    }

    /**
     * THE DEPARTMENT KPI CONTRIBUTION POINT. The tag is applied BY HAND in the extension (a
     * reusable bundle is not autoconfigured), and a provider that failed to
     * register would show up only as every incidents plate quietly vanishing from
     * every performance page. This test is what makes that loud.
     */
    public function testItReachesTheDepartmentPerformanceContract(): void
    {
        self::bootKernel();

        /** @var CollectedKpiProviders $providers */
        $providers = self::getContainer()->get(CollectedKpiProviders::class);

        self::assertArrayHasKey('incidents', $providers->bySlug());
    }

    /**
     * THE ZONE FIGURE CONTRIBUTION POINT, tagged by hand in the extension for
     * the same reason and with the same failure mode: an untagged provider is
     * invisible everywhere except as incidents cards missing from every zone
     * surface.
     */
    public function testItReachesTheZoneFigureContract(): void
    {
        self::bootKernel();

        /** @var CollectedZoneFigureProviders $providers */
        $providers = self::getContainer()->get(CollectedZoneFigureProviders::class);

        self::assertArrayHasKey('incidents', $providers->bySlug());
    }

    /**
     * THE STATION FIGURE CONTRIBUTION POINT, tagged by hand for the same
     * reason: an untagged provider is invisible everywhere except as the
     * incidents row missing from every station dock.
     */
    public function testItReachesTheStationFigureContract(): void
    {
        self::bootKernel();

        /** @var CollectedStationFigureProviders $providers */
        $providers = self::getContainer()->get(CollectedStationFigureProviders::class);

        self::assertArrayHasKey('incidents', $providers->bySlug());
    }

    /**
     * The category a deployment configures is the category the host files the
     * tile under — the config value has to reach the provider, not just the
     * container.
     */
    public function testTheConfiguredCategoryReachesTheProvider(): void
    {
        self::bootKernel();

        /** @var CollectedModules $catalogue */
        $catalogue = self::getContainer()->get(CollectedModules::class);

        self::assertSame('operations', $catalogue->bySlug()['incidents']->category());
    }

    protected function tearDown(): void
    {
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
}
