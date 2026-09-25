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

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\AtlasBundle\Model\Ground;

/**
 * EVERY INCIDENTS PLATE STANDS ON THE AREA'S GROUND, as a browser is served
 * it: the zones layer first, and the legend opening on "The area" — the
 * boundary row, then "Zones · N" — the same legend shape the patrol plates
 * wear. This module draws no zone layer of its own.
 */
final class AreaGroundOnPlatesTest extends FunctionalTestCase
{
    public function testTheDashboardMapStandsOnTheAreasGround(): void
    {
        $area = $this->anAreaWithKinds();
        $this->aZone($area, 'North Gate');
        $this->aZone($area, 'Highland Ward');
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));
        self::assertResponseIsSuccessful();

        $plates = $crawler->filter('.map-plate');
        self::assertGreaterThan(0, $plates->count());
        $plates->each(static function (Crawler $plate): void {
            self::assertStandsOnTheGround($plate, 2);
        });
    }

    public function testTheCaseFilePlateStandsOnTheAreasGround(): void
    {
        $area = $this->anAreaWithKinds();
        $this->aZone($area, 'North Gate');
        $incident = $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $incident->getReference()));
        self::assertResponseIsSuccessful();

        self::assertStandsOnTheGround($crawler->filter('.map-plate')->first(), 1);
    }

    public function testAnAreaWithNoZonesServesZonesNought(): void
    {
        $area = $this->anAreaWithKinds();
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));
        self::assertResponseIsSuccessful();

        self::assertStandsOnTheGround($crawler->filter('[data-w="map"] .map-plate'), 0);
    }

    private static function assertStandsOnTheGround(Crawler $plate, int $zones): void
    {
        self::assertCount(1, $plate);

        $extra = json_decode((string) $plate->filter('.map-canvas')->attr('data-symfony--ux-leaflet-map--map-extra-value'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($extra);
        $atlas = $extra['atlas'] ?? null;
        self::assertIsArray($atlas);
        $layers = $atlas['layers'] ?? null;
        self::assertIsList($layers);
        self::assertIsArray($layers[0]);

        // The zones are the first layer, so every incident mark sits over them.
        self::assertSame(Ground::ZONES_LAYER_ID, $layers[0]['id']);
        $features = $layers[0]['features'];
        self::assertIsArray($features);
        self::assertIsList($features['features']);
        self::assertCount($zones, $features['features']);
        self::assertNotContains('incident.zones', array_column($layers, 'id'));

        // The legend opens on "The area": the boundary row, then Zones · N.
        $group = $plate->filter('.map-legend .grp')->first();
        self::assertSame(Ground::GROUP, trim($group->filter('b')->text()));
        $rows = $group->filter('.lay');
        self::assertCount(2, $rows);
        self::assertStringStartsWith('Boundary', trim($rows->eq(0)->text()));
        self::assertStringStartsWith('Zones', trim($rows->eq(1)->text()));
        self::assertSame((string) $zones, $rows->eq(1)->filter('em')->text());
    }
}
