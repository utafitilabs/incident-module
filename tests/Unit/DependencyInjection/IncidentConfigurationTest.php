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

namespace Uhifadhi\Incident\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Uhifadhi\Incident\DependencyInjection\IncidentConfiguration;

final class IncidentConfigurationTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        $builder = new TreeBuilder('incidents');
        IncidentConfiguration::define($builder->getRootNode());

        /** @var array<string, mixed> $processed */
        $processed = new Processor()->process($builder->buildTree(), ['incidents' => $config]);

        return $processed;
    }

    public function testDefaultsFileTheModuleUnderOperations(): void
    {
        $config = $this->process([]);

        self::assertSame('operations', $config['module_category']);
    }

    /**
     * NO KNOB THAT GATES NOTHING. `dev_tools` existed to keep the seeder out
     * of production; the seed content is a devkit provider now, and devkit is
     * `require-dev`, so the dependency graph is the firewall and the key would
     * turn nothing off. The tree is closed, so a deployment that still writes it
     * is told rather than ignored.
     */
    public function testDevToolsIsNoLongerAKeyThisModuleAnswersTo(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['dev_tools' => true]);
    }

    public function testADeploymentFilesTheModuleWhereItWants(): void
    {
        self::assertSame('biodiversity', $this->process(['module_category' => 'biodiversity'])['module_category']);
    }

    public function testAnEmptyCategoryIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['module_category' => '']);
    }

    public function testTheTreeIsClosedToUnknownKeys(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        // Until the design rules on the domain model there is no taxonomy to
        // configure; an invented key must fail loudly rather than be ignored.
        $this->process(['categories' => ['poaching' => ['label' => 'Poaching']]]);
    }
}
