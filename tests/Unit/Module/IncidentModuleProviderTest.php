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

namespace Uhifadhi\Incident\Tests\Unit\Module;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\ModuleProviderInterface;
use Uhifadhi\Incident\Module\IncidentModuleProvider;

final class IncidentModuleProviderTest extends TestCase
{
    public function testItSaysWhatItIsInOneSentence(): void
    {
        self::assertSame('Everything reported — from a patrol or walked in at a gate.', new IncidentModuleProvider('operations')->description());
    }

    public function testDeclaresTheIncidentsModule(): void
    {
        $provider = new IncidentModuleProvider('operations');

        self::assertInstanceOf(ModuleProviderInterface::class, $provider);
        self::assertSame('incidents', $provider->slug());
        self::assertSame('Incidents', $provider->name());
        self::assertSame('operations', $provider->category());
        self::assertSame('Field incident reports', $provider->dataSource());
        self::assertSame('triangle-alert', $provider->icon());
    }

    public function testCategoryIsDeploymentConfigured(): void
    {
        self::assertSame('biodiversity', new IncidentModuleProvider('biodiversity')->category());
    }

    /**
     * The module owns its screens, so the host's tile links straight to the
     * incidents dashboard rather than to the generic module page.
     */
    public function testTheHostLinksStraightToTheIncidentsDashboard(): void
    {
        self::assertSame('incident_dashboard', new IncidentModuleProvider('operations')->entryRoute());
    }
}
