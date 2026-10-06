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

namespace Uhifadhi\Incident\Tests\Functional\Authority;

use ApiPlatform\Symfony\Bundle\ApiPlatformBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use League\FlysystemBundle\FlysystemBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\UX\Chartjs\ChartjsBundle;
use Symfony\UX\Icons\UXIconsBundle;
use Symfony\UX\Map\UXMapBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Uhifadhi\Bundle\AreaBundle\AreaBundle;
use Uhifadhi\Bundle\AtlasBundle\AtlasBundle;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Security\ApiTokenAuthenticator;
use Uhifadhi\Bundle\TeamBundle\TeamBundle;
use Uhifadhi\Contracts\Queue\AsyncMessageInterface;
use Uhifadhi\Incident\UhifadhiIncidentBundle;
use Uhifadhi\Storage\UhifadhiStorageBundle;
use UtafitiLabs\PostGISBundle\UtafitiLabsPostGISBundle;

/**
 * THE INSTALLATION THE AUTHORITY TABLE CALLS INCIDENTS IN: every core bundle,
 * the storage the case file keeps its evidence in, the skeleton's security
 * file, and this module — so a cell answers what a real installation
 * answers, sign-in redirect and all.
 *
 * IT IS NOT THE SUITE'S {@see \Uhifadhi\Incident\Tests\Integration\TestKernel}.
 * That kernel lets a fixture voter decide who holds this module's pairs, so
 * its pages can be tested as a reporter, a manager and a clerk. Here nobody
 * stands in: the core's own voters answer, against positions and placements,
 * so the table and the Permissions page read the rules an installation runs.
 *
 * @see vendor/uhifadhi/uhifadhi/tests/Application/Kernel.php — the core's own throwaway application, the shape copied here
 * @see https://symfony.com/doc/current/testing.html#application-tests
 */
final class AuthorityKernel extends Kernel
{
    use MicroKernelTrait;

    public static function directory(string $place): string
    {
        return sys_get_temp_dir().'/incident-module-tests/authority/'.$place;
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new DoctrineMigrationsBundle();
        yield new UtafitiLabsPostGISBundle();
        yield new TwigBundle();
        yield new UXIconsBundle();
        yield new StimulusBundle();
        yield new UXMapBundle();
        yield new ChartjsBundle();
        yield new SecurityBundle();
        yield new ApiPlatformBundle();
        yield new RegistryBundle();
        yield new ShellBundle();
        yield new AtlasBundle();
        yield new TeamBundle();
        yield new AreaBundle();
        yield new FlysystemBundle();
        yield new UhifadhiStorageBundle();
        yield new UhifadhiIncidentBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'router' => ['utf8' => true],
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection' => ['enabled' => true],
            'property_access' => true,
            'property_info' => ['enabled' => true],
            'serializer' => ['enabled' => true],
            'validation' => ['enabled' => true],
            'assets' => true,
            'asset_mapper' => ['paths' => [$this->getProjectDir().'/assets' => '']],
            // THE QUEUE AS THE CORE'S RECIPE WRITES IT: the Doctrine transport
            // on the installation's own connection, and every message carrying
            // the core's marker sent to it.
            // @see https://symfony.com/doc/current/messenger.html#doctrine-transport
            'messenger' => [
                'failure_transport' => 'failed',
                'transports' => [
                    'async' => ['dsn' => 'doctrine://default?auto_setup=0', 'options' => ['queue_name' => 'async']],
                    'failed' => ['dsn' => 'doctrine://default?auto_setup=0', 'options' => ['queue_name' => 'failed']],
                ],
                'routing' => [AsyncMessageInterface::class => 'async'],
            ],
        ]);

        $container->extension('ux_icons', [
            'icon_dir' => \dirname(__DIR__, 2).'/Integration/Fixtures/icons',
            'iconify' => ['on_demand' => false],
        ]);

        $container->extension('ux_map', ['renderer' => 'leaflet://default']);

        $container->extension('api_platform', [
            'title' => 'Incident module authority table',
            'version' => '1.0.0',
            'formats' => ['json' => ['application/json']],
            'error_formats' => ['json' => ['application/problem+json', 'application/json']],
            'defaults' => ['stateless' => true],
        ]);

        // The skeleton's two settings that decide the DDL, so the schema the
        // table runs on is the one an installation has.
        $container->extension('doctrine', [
            'dbal' => ['url' => '%env(INCIDENTS_TEST_DATABASE_URL)%'],
            'orm' => [
                'controller_resolver' => ['auto_mapping' => false],
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore',
                'identity_generation_preferences' => [
                    PostgreSQLPlatform::class => 'identity',
                ],
            ],
        ]);

        $container->extension('storage', [
            'evidence' => [
                'adapter' => 'local',
                'directory' => self::directory('evidence'),
            ],
        ]);

        $container->services()->set('logger', NullLogger::class);

        // The skeleton's security file: default-closed, the sign-in form on
        // the web firewall, the tier ladder and nothing per module.
        $container->extension('security', [
            'password_hashers' => [
                PasswordAuthenticatedUserInterface::class => [
                    'algorithm' => 'auto',
                    'cost' => 4,
                    'time_cost' => 3,
                    'memory_cost' => 10,
                ],
            ],
            'providers' => [
                'team_user_provider' => [
                    'entity' => ['class' => User::class, 'property' => 'email'],
                ],
            ],
            'firewalls' => [
                'api_auth' => ['pattern' => '^/api/auth/token$', 'security' => false],
                'api' => [
                    'pattern' => '^/api',
                    'stateless' => true,
                    'provider' => 'team_user_provider',
                    'user_checker' => 'team.user_checker',
                    'custom_authenticators' => [ApiTokenAuthenticator::class],
                    'entry_point' => ApiTokenAuthenticator::class,
                ],
                'main' => [
                    'lazy' => true,
                    'provider' => 'team_user_provider',
                    'user_checker' => 'team.user_checker',
                    'form_login' => [
                        'login_path' => 'team_login',
                        'check_path' => 'team_login',
                        'enable_csrf' => true,
                        'default_target_path' => '/',
                    ],
                    'logout' => ['path' => 'team_logout', 'target' => 'team_login'],
                ],
            ],
            'role_hierarchy' => [
                'ROLE_ADMIN' => ['ROLE_USER'],
                'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH'],
            ],
            'access_control' => [
                ['path' => '^/login', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/reset-password', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/invite/', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/api/auth/token$', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/api', 'roles' => 'ROLE_USER'],
                ['path' => '^/', 'roles' => 'ROLE_USER'],
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // What an installation mounts: the shell's resources, the field API,
        // the team's and the area's screens, storage's — and this module's,
        // as its recipe mounts them.
        $routes->import(ShellBundle::ROUTES);
        $routes->import(ShellBundle::CONFIGURE_ROUTES);
        $routes->import(ShellBundle::SETTINGS_ROUTES);
        $routes->import('.', 'api_platform')->prefix('/api');
        $routes->import('@TeamBundle/Controller/', 'attribute');
        $routes->import('@AreaBundle/Controller/', 'attribute');
        $routes->import('@UhifadhiStorageBundle/src/Controller/', 'attribute');
        $routes->import('@UhifadhiIncidentBundle/src/Controller/', 'attribute');
    }

    /** The suite's stand-in application: an importmap and an assets directory, nothing else. */
    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2).'/Integration/Fixtures/app';
    }

    public function getCacheDir(): string
    {
        return self::directory('cache');
    }

    public function getLogDir(): string
    {
        return self::directory('log');
    }
}
