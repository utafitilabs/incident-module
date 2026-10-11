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

/**
 * ONE CONFIGURE BUTTON, ONE CONFIGURE PAGE — the shell's page over this
 * module's declared sections, and the one POST behind its Settings body.
 */
final class ConfigurePageTest extends FunctionalTestCase
{
    /** One card of the section body, found by the word in its caption. */
    private static function card(Crawler $crawler, string $heading): Crawler
    {
        return $crawler->filter('.c')->reduce(
            static fn (Crawler $card): bool => str_starts_with(trim($card->filter('.tab')->text('')), $heading),
        );
    }

    private function configureUrl(AreaOfInterest $area, ?string $section = null): string
    {
        return \sprintf(
            '/areas/%s/modules/incidents/configure%s',
            $this->uuidOf($area),
            null === $section ? '' : '/'.$section,
        );
    }

    /** The bare address is the surface's settings, by the platform's ruled order. */
    public function testTheSettingsSectionNamesThisModule(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->configureUrl($area, 'settings'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sample Area — Incidents · configure', $crawler->filter('h1.pg')->text());
        self::assertSame(
            ['Widget library', 'Incident kinds', 'Lists', 'Settings'],
            $crawler->filter('.atabs a')->each(static fn (Crawler $a): string => trim($a->text())),
        );
        self::assertSame('Settings', trim($crawler->filter('.atabs a.on')->text()));
    }

    /** The section body is the module's, and it is a body: no head, no strip. */
    public function testTheSettingsBodyDrawsTheCurrencyAndTheKinds(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->configureUrl($area, 'settings'));

        self::assertSame(
            ['Currency', 'Kinds'],
            $crawler->filter('.c > .tab')->each(
                static fn (Crawler $t): string => trim(str_replace((string) $t->filter('.src')->text(''), '', $t->text())),
            ),
        );
        self::assertGreaterThan(0, $crawler->filter('select[name="currency"] option')->count());
    }

    /**
     * The Kinds card no longer says a sub-category's term and fields are editable
     * nowhere — they are, in the Incident kinds section — so the note that said so
     * is gone, and what replaced it points at that section.
     */
    public function testTheKindsCardSendsTermAndFieldsToTheKindsSection(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->configureUrl($area, 'settings'));
        $kinds = self::card($crawler, 'Kinds')->text();

        self::assertStringNotContainsString('not editable here yet', $kinds);
        self::assertStringContainsString('the term it promises', $kinds);
        self::assertStringContainsString('Incident kinds', $kinds);
    }

    /** Until an area saves, it counts money in the installation's currency. */
    public function testAnAreaThatHasNeverSavedShowsTheInstallationsCurrency(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->configureUrl($area, 'settings'));

        self::assertStringContainsString('the installation', self::card($crawler, 'Currency')->text());
    }

    /** One POST, and the area counts money in its own currency from then on. */
    public function testSavingTheSettingsWritesTheAreasOwnCurrency(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->configureUrl($area, 'settings'));
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', $this->configureUrl($area, 'settings'), [
            '_token' => $token,
            'currency' => 'KES',
        ]);

        self::assertResponseRedirects($this->configureUrl($area, 'settings'));

        $crawler = $this->client->request('GET', $this->configureUrl($area, 'settings'));
        self::assertSame('KES', $crawler->filter('select[name="currency"] option[selected]')->attr('value'));
        self::assertStringContainsString('this area’s own', self::card($crawler, 'Currency')->text());
    }

    /** A select is not a security boundary: a code nobody offered is refused. */
    public function testAPostedCurrencyTheDesignDoesNotOfferIsRefused(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->configureUrl($area, 'settings'));
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', $this->configureUrl($area, 'settings'), [
            '_token' => $token,
            'currency' => 'XXX',
        ]);

        $crawler = $this->client->request('GET', $this->configureUrl($area, 'settings'));
        self::assertNotSame('XXX', $crawler->filter('select[name="currency"] option[selected]')->attr('value'));
    }

    /** Changing what an area runs on rides on `incident-vocabulary.configure`. */
    public function testSomebodyWhoMayNotManageCannotSaveTheSettings(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $this->client->request('POST', $this->configureUrl($area, 'settings'), ['currency' => 'KES']);

        self::assertResponseStatusCodeSame(403);
    }

    /** A renamed screen has one address: `…/taxonomy` is gone, not redirected. */
    public function testTheOldTaxonomyAddressIsGone(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aManager());

        $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/taxonomy', $this->uuidOf($area)));

        self::assertResponseStatusCodeSame(404);
    }

    /** The word "register" is nowhere on the configure page either. */
    public function testNothingOnTheConfigurePageSaysRegister(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aManager());

        $this->client->request('GET', $this->configureUrl($area, 'settings'));

        self::assertStringNotContainsStringIgnoringCase(
            'register',
            (string) $this->client->getResponse()->getContent(),
        );
    }
}
