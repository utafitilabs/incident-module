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
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingChange;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingsWriter;
use Uhifadhi\Contracts\Settings\SettingDepth;
use Uhifadhi\Incident\Settings\PlacementSettings;

/**
 * WHERE A POINT LIES AGAINST THE AREA, asked by the report form while the
 * point is being placed, so the banner is there before anything is filed.
 * The area's boundary is -30..-29 E, -3.6..-2.8 N; its one zone covers the
 * western half.
 */
final class PlacementEndpointTest extends FunctionalTestCase
{
    public function testAPointInsideNamesItsZoneAndDrawsNoBanner(): void
    {
        $area = $this->anAreaWithKinds('Kilimani');
        $this->aZone($area, 'Riverbend');

        $answer = $this->ask($area, '-3.21', '-29.75');

        self::assertResponseIsSuccessful();
        self::assertSame('inside', $answer['placement']);
        self::assertSame(0.0, $answer['metres']);
        self::assertSame('warn', $answer['setting']);
        self::assertSame('Kilimani', $answer['area']);
        self::assertSame('Riverbend', $answer['zone']);
        self::assertSame('', trim($answer['banner']));
        self::assertSame('Riverbend', trim(new Crawler($answer['where'])->filter('.i-zone')->text()));
    }

    public function testAPointOutsideIsOutsideByItsDistanceAndCarriesTheFormsBanner(): void
    {
        $area = $this->anAreaWithKinds('Kilimani');

        $answer = $this->ask($area, '-3.21', '-28.989');

        self::assertSame('outside', $answer['placement']);
        self::assertEqualsWithDelta(1220.0, (float) $answer['metres'], 15.0);
        self::assertNull($answer['zone']);
        $banner = new Crawler($answer['banner']);
        self::assertSame('This point is outside Kilimani, 1.2 km beyond its boundary. — Move it inside, or file it as outside the area.', trim($banner->filter('.banner-t')->text()));
        self::assertSame(['Move the point', 'File it outside the area'], $banner->filter('.banner-acts .btn')->each(static fn (Crawler $b): string => trim($b->text())));
        self::assertSame('outside', $banner->filter('button[name="placement"]')->attr('value'));
        self::assertSame('outside Kilimani · no zone', trim(new Crawler($answer['where'])->text()));
    }

    public function testAPointWithinTheMarginCountsAsInside(): void
    {
        $area = $this->anAreaWithKinds('Kilimani');

        $answer = $this->ask($area, '-3.21', '-28.997');

        self::assertSame('inside', $answer['placement']);
        self::assertGreaterThan(300.0, (float) $answer['metres']);
        self::assertSame('', trim($answer['banner']));
    }

    public function testAnAreaThatRefusesOffersOnlyMovingThePointAndOwesAPointInside(): void
    {
        $area = $this->anAreaWithKinds('Kilimani');
        $this->set($area, PlacementSettings::OUTSIDE_BOUNDARY, 'refuse');

        $answer = $this->ask($area, '-3.21', '-28.989');

        self::assertSame('refuse', $answer['setting']);
        $banner = new Crawler($answer['banner']);
        self::assertSame('This point is outside Kilimani, 1.2 km beyond its boundary. — This area files reports inside its boundary only.', trim($banner->filter('.banner-t')->text()));
        self::assertSame(['Move the point'], $banner->filter('.banner-acts .btn')->each(static fn (Crawler $b): string => trim($b->text())));
        self::assertCount(1, $banner->filter('input[type="hidden"][data-need="a point inside Kilimani"]'));
    }

    public function testAnAreaThatAcceptsSaysWhereButDrawsNoBanner(): void
    {
        $area = $this->anAreaWithKinds('Kilimani');
        $this->set($area, PlacementSettings::OUTSIDE_BOUNDARY, 'accept');

        $answer = $this->ask($area, '-3.21', '-28.989');

        self::assertSame('outside', $answer['placement']);
        self::assertSame('accept', $answer['setting']);
        self::assertSame('', trim($answer['banner']));
        self::assertSame('outside Kilimani · no zone', trim(new Crawler($answer['where'])->text()));
    }

    public function testTheAreasOwnMarginDecides(): void
    {
        $area = $this->anAreaWithKinds('Kilimani');
        $this->set($area, PlacementSettings::INSIDE_WITHIN, 100);

        $answer = $this->ask($area, '-3.21', '-28.997');

        self::assertSame('outside', $answer['placement']);
        self::assertStringContainsString('330 m beyond its boundary', new Crawler($answer['banner'])->text());
    }

    public function testAnUnreadablePointIsABadRequest(): void
    {
        $area = $this->anAreaWithKinds('Kilimani');
        $this->client->loginUser($this->aReporter());

        $this->client->request('GET', $this->url($area).'?lat=north&lng=-29.75');

        self::assertResponseStatusCodeSame(400);
    }

    public function testSomebodyWhoMayNotReportIsRefused(): void
    {
        $area = $this->anAreaWithKinds('Kilimani');
        $this->client->loginUser($this->aUser('bystander@example.test', 'No', 'Rights'));

        $this->client->request('GET', $this->url($area).'?lat=-3.21&lng=-28.989');

        self::assertResponseStatusCodeSame(403);
    }

    /** The report form names the address it asks, on its controller. */
    public function testTheReportFormCarriesTheAddressItAsks(): void
    {
        $area = $this->anAreaWithKinds('Kilimani');
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/new', $this->uuidOf($area)));

        self::assertSame($this->url($area), $crawler->filter('[data-uhifadhi--incident-module--incident-report-placement-url-value]')->attr('data-uhifadhi--incident-module--incident-report-placement-url-value'));
        self::assertCount(1, $crawler->filter('form [data-uhifadhi--incident-module--incident-report-target="placement"]'));
        self::assertCount(1, $crawler->filter('form [data-uhifadhi--incident-module--incident-report-target="where"]'));
    }

    /** A form arriving with a point outside the area is told on its first paint. */
    public function testAFormArrivingWithAPointOutsideShowsTheBannerAtOnce(): void
    {
        $area = $this->anAreaWithKinds('Kilimani');
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/new?lat=-3.21&lng=-28.989', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form .banner'));
        self::assertStringContainsString('outside Kilimani · no zone', $crawler->filter('form [data-uhifadhi--incident-module--incident-report-target="where"]')->text());
    }

    /** @return array{placement: string, metres: float|null, setting: string, area: string, zone: string|null, banner: string, where: string} */
    private function ask(AreaOfInterest $area, string $lat, string $lng): array
    {
        $this->client->loginUser($this->aReporter());
        $this->client->request('GET', $this->url($area).'?'.http_build_query(['lat' => $lat, 'lng' => $lng]));
        self::assertResponseIsSuccessful();

        /** @var array{placement: string, metres: float|null, setting: string, area: string, zone: string|null, banner: string, where: string} $answer */
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $answer;
    }

    private function url(AreaOfInterest $area): string
    {
        return \sprintf('/areas/%s/modules/incidents/placement', $this->uuidOf($area));
    }

    private function set(AreaOfInterest $area, string $key, int|string $value): void
    {
        /** @var SettingsWriter $writer */
        $writer = static::getContainer()->get('test_public.registry.settings.writer');
        $writer->apply([new SettingChange($key, SettingDepth::Area, $this->uuidOf($area), $value)], 'test');
    }
}
