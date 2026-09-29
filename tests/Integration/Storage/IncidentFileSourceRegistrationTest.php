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

namespace Uhifadhi\Incident\Tests\Integration\Storage;

use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvidence;
use Uhifadhi\Incident\Enum\EvidenceKindEnum;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Storage\IncidentFileSource;
use Uhifadhi\Incident\Tests\Integration\Fixtures\CollectedFileSources;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Storage\Enum\GuardStateEnum;
use Uhifadhi\Storage\Registry\FileRegistry;
use Uhifadhi\Storage\Registry\FileSourceInterface;

/**
 * INCIDENTS REACHES THE FILES HUB — through the tag, not through a template.
 *
 * A source that is written but not tagged fails invisibly: the hub grows by
 * MODULES, so a module whose source was never collected looks exactly like a
 * module nobody installed. Nothing throws and nothing is logged; there is simply
 * one fewer heading on /files. That is why this test asks the REGISTRY — the
 * same tagged iterator the hub reads — rather than the source directly.
 */
final class IncidentFileSourceRegistrationTest extends IntegrationTestCase
{
    public function testTheSourceIsCollectedByTheRegistryTheHubReads(): void
    {
        $slugs = array_column($this->registry()->modules(), 'slug');

        self::assertContains('incidents', $slugs);
    }

    /**
     * AND UNDER THE TAG THE CORE PUBLISHES, which is not the one the hub used
     * to own. A module declaring that it stores files is a fact the whole
     * platform reads, so the declaration and the files arrive on ONE tag —
     * `uhifadhi.file_source` — and a second would let the two disagree.
     *
     * The collector this asks is wired on the tag's literal spelling rather
     * than on the interface constant, because a constant follows its own
     * definition and would keep this passing through a rename it is here to
     * catch.
     */
    public function testTheSourceIsCollectedUnderThePlatformsOwnTag(): void
    {
        /** @var CollectedFileSources $collected */
        $collected = self::getContainer()->get(CollectedFileSources::class);
        $words = $collected->wordBySlug();

        self::assertArrayHasKey('incidents', $words);
        self::assertSame(IncidentFileSource::FILE_WORD, $words['incidents']);
        self::assertSame('uhifadhi.file_source', FileSourceInterface::TAG);
    }

    /**
     * A module that ships a source but holds nothing is still LISTED — "we have
     * that and it is empty" is a different fact from "we do not have it".
     */
    public function testAModuleHoldingNoEvidenceStillAppears(): void
    {
        self::assertSame([], $this->registry()->all());

        // The test kernel also registers a stub standing for ANOTHER module on
        // the far side of the cross-module file contract, so this asks about
        // incidents' own row rather than about the only row.
        $modules = array_values(array_filter(
            $this->registry()->modules(),
            static fn (array $module): bool => 'incidents' === $module['slug'],
        ));

        self::assertCount(1, $modules);
        self::assertSame(0, $modules[0]['files']);
        self::assertSame('Incidents', $modules[0]['label']);
        self::assertNotSame('', $modules[0]['attachesTo']);
    }

    /**
     * THE ROWS THE SEED SEEDER WRITES HAVE NO BYTES. They are records of
     * photographs, not photographs: no path, and therefore no key. The hub is
     * told about none of them, because a tile for a key that names nothing would
     * link at a 404 — the module is simply listed as holding nothing.
     */
    public function testEvidenceWithNoStoredBytesIsNotShownAsAFile(): void
    {
        $incident = $this->incident();
        new IncidentEvidence($incident, EvidenceKindEnum::Photo, 'IMG_1204.jpg')
            ->setCapturedAt(new \DateTimeImmutable('2026-08-19 12:10:00'));
        $this->em->flush();

        self::assertSame([], $this->registry()->all());
        self::assertSame(0, $this->registry()->modules()[0]['files']);
    }

    public function testStoredEvidenceReachesTheHubCarryingItsCaseFileAndItsArea(): void
    {
        $incident = $this->incident();
        $evidence = new IncidentEvidence($incident, EvidenceKindEnum::Photo, 'IMG_1204.jpg')
            ->setPath('incident/'.$incident->getUuid()->toRfc4122().'/e77c.jpg')
            ->setCapturedAt(new \DateTimeImmutable('2026-08-19 12:10:00'))
            ->setCaption('The broken fence line, looking north.');
        $this->em->flush();

        $files = $this->registry()->all();
        self::assertCount(1, $files);

        $entry = $files[0];
        self::assertSame($evidence->getPath(), $entry->key);
        self::assertSame('IMG_1204.jpg', $entry->name);
        self::assertSame($incident->getReference(), $entry->ownerLabel);
        self::assertSame('The broken fence line, looking north.', $entry->caption);
        self::assertIsString($entry->ownerUrl);
        self::assertStringContainsString($incident->getReference(), (string) $entry->ownerUrl);
    }

    /** An incident still being worked will not let go of evidence a claim rests on. */
    public function testAnOpenCaseFileLocksItsEvidence(): void
    {
        $incident = $this->incident();
        $key = 'incident/'.$incident->getUuid()->toRfc4122().'/e77c.jpg';
        new IncidentEvidence($incident, EvidenceKindEnum::Photo, 'IMG_1204.jpg')->setPath($key);
        $this->em->flush();

        $guard = $this->registry()->guard($key, null);

        self::assertSame(GuardStateEnum::Locked, $guard->state);
        self::assertFalse($guard->offersRemoval());
    }

    public function testAResolvedCaseFileLetsGo(): void
    {
        $incident = $this->incident()->setStatus(IncidentStatusEnum::Resolved);
        $key = 'incident/'.$incident->getUuid()->toRfc4122().'/e77c.jpg';
        new IncidentEvidence($incident, EvidenceKindEnum::Photo, 'IMG_1204.jpg')->setPath($key);
        $this->em->flush();

        $guard = $this->registry()->guard($key, null);

        self::assertSame(GuardStateEnum::Allowed, $guard->state);
        self::assertTrue($guard->offersRemoval());
    }

    /**
     * A key under this module's prefix that no row holds gets the honest refusal
     * rather than an invented permission.
     */
    public function testAKeyNoCaseFileHoldsIsLocked(): void
    {
        $guard = $this->registry()->guard('incident/nothing/at-all.jpg', null);

        self::assertSame(GuardStateEnum::Locked, $guard->state);
    }

    /** A real filed incident, through the module's own door. */
    private function incident(): Incident
    {
        return $this->anIncident($this->anAreaWithKinds('Kifaru Sector'));
    }

    private function registry(): FileRegistry
    {
        /** @var FileRegistry $registry */
        $registry = $this->service('storage.file_registry');

        return $registry;
    }
}
