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

namespace Uhifadhi\Incident\Storage;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvidence;
use Uhifadhi\Incident\Enum\EvidenceKindEnum;
use Uhifadhi\Incident\Repository\IncidentEvidenceRepository;
use Uhifadhi\Incident\Service\IncidentEvidenceKey;
use Uhifadhi\Storage\Enum\FileKindEnum;
use Uhifadhi\Storage\Enum\GuardStateEnum;
use Uhifadhi\Storage\Enum\ThumbStateEnum;
use Uhifadhi\Storage\Model\FileEntry;
use Uhifadhi\Storage\Model\FileGuard;
use Uhifadhi\Storage\Registry\FileSourceInterface;
use Uhifadhi\Storage\Registry\HoldsNoRecordFilesTrait;

/**
 * AN INCIDENT'S EVIDENCE, ON THE PLATFORM'S FILES HUB.
 *
 * The hub at /files knows nothing about incidents and cannot: knowing what a
 * photograph is attached to is what makes a module a module. So each piece of
 * evidence is handed over here already carrying its case file, and the module
 * answers the one question the hub must never answer for itself — what may be
 * done to it.
 *
 * WHAT IS HONEST HERE. A piece of evidence carries what was recorded when its
 * blob was written — a detected mime type, a measured byte size and, for a
 * photograph, a generated preview. The seeder writes those bytes through
 * the platform's own EvidenceStorage (photographs) and its evidence storage
 * (signed documents), so the sample month appears on the hub with real sizes and
 * thumbnails. A row that carries only a KEY and no stored bytes — evidence keyed
 * before a blob was written — is still described
 * truthfully rather than conveniently, and three rules in the mapping below make
 * that so:
 *
 *   - A row with NO path is not yielded. A file is its key, and a tile for a key
 *     that names nothing would link at a 404. Un-stored evidence therefore does
 *     not appear until it is genuinely keyed — and "we have that and it is empty"
 *     is exactly the fact the hub is designed to show.
 *   - Where no size was recorded the figure is 0, because nobody measured it —
 *     the hub's space bars must not add up bytes that were never counted — and
 *     the type is read off the filename, the only fact such a row carries.
 *   - A photograph with no preview is Waiting, not Failed. Failed says this
 *     machine tried and could not decode the file; a key-only row never tried.
 *
 * Still not modelled, and deliberately: a guard that answers Denied for evidence
 * another department uploaded. IncidentEvidence records no uploader, so that
 * column arrives before the answer does — and nothing else here changes.
 */
final class IncidentFileSource implements FileSourceInterface
{
    /*
     * INCIDENTS DOES NOT PUBLISH ITS EVIDENCE BY RECORD — yet. The contract allows it so
     * one module can draw another's record; nothing today shows an incident's
     * evidence from outside incidents, and a source that answered would be
     * guessing at a consumer that does not exist. When one does, this trait comes
     * off and the method is written.
     */
    use HoldsNoRecordFilesTrait;

    /** The module slug the hub counts incidents by, and the colour files.css draws its dot in. */
    public const string SLUG = 'incidents';

    public const string LABEL = 'Incidents';

    /**
     * WHAT THIS MODULE CALLS ONE FILE, printed verbatim in the Sources
     * register's "What it calls a file" column. The hub has no word of its own
     * for somebody else's files and must not invent one.
     *
     * A phrase and not a noun, because a noun cannot say WHOSE the files are,
     * and whose is the half of the answer that tells a reader where to go and
     * change one.
     *
     * TWO SURFACES, ONE ANSWER. The Sources register's column and the "Modules
     * holding files" widget's line are different sentences on different pages,
     * and this module's answer to both is the same words — so a reader who has
     * seen one recognises the other. {@see attachesTo()} returns this.
     */
    public const string FILE_WORD = 'evidence — photographs and documents';

    public function __construct(
        private readonly IncidentEvidenceRepository $evidence,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function moduleSlug(): string
    {
        return self::SLUG;
    }

    public function moduleLabel(): string
    {
        return self::LABEL;
    }

    public function fileWord(): string
    {
        return self::FILE_WORD;
    }

    public function attachesTo(): string
    {
        return self::FILE_WORD;
    }

    public function claimsKey(string $key): bool
    {
        return IncidentEvidenceKey::claims($key);
    }

    /**
     * @return iterable<FileEntry>
     */
    public function files(): iterable
    {
        foreach ($this->evidence->findForFilesHub() as $evidence) {
            if (null === $evidence->getPath()) {
                continue;
            }

            yield self::entryFor($evidence, $this->caseFileUrl($evidence->getIncident()));
        }
    }

    /**
     * A RUN OF EVIDENCE, DESCRIBED THE WAY THE HUB DESCRIBES IT — so an evidence
     * tile on a case file, one on the dashboard and one on /files answer the same
     * three questions about the same photograph from one mapping. Which state a
     * picture is in is this module's answer to give ({@see entryFor()}); drawing
     * it is the storage's, and a second opinion here is a queued spinner over a
     * picture that has been sitting there for a month.
     *
     * A row with no path is not yielded, for the reason {@see files()} gives: a
     * file is its key, and a tile for a key that names nothing has nothing to open.
     *
     * @param iterable<IncidentEvidence> $evidence
     *
     * @return list<FileEntry>
     */
    public function entriesOf(iterable $evidence): array
    {
        $entries = [];
        foreach ($evidence as $item) {
            if (null === $item->getPath()) {
                continue;
            }

            $entries[] = self::entryFor($item, $this->caseFileUrl($item->getIncident()));
        }

        return $entries;
    }

    public function guard(string $key, ?UserInterface $user): FileGuard
    {
        return self::guardFor($this->evidence->findOneByPath($key)?->getIncident());
    }

    /**
     * WHOSE ANSWER THIS IS: the case file's, in the incidents module's own words.
     *
     * An incident still being worked will not let go of evidence a claim rests
     * on — a compensation file that could quietly lose the photograph of the
     * dead livestock is a file worth nothing at the hearing it exists for. Once
     * the case is resolved and filed, the record no longer needs to hold on.
     *
     * $incident is null where the key is under this module's prefix but no row
     * holds it. Locked rather than denied, matching the hub's own reading of an
     * unclaimed key: nothing here can authorise removing bytes nothing here
     * admits to owning, and that is not the reader's fault.
     *
     * NOT IMPLEMENTED, and deliberately: storage-module's design also draws a
     * Denied — "evidence uploaded by another department is not yours to remove".
     * IncidentEvidence records no uploader and no department, so answering Denied
     * would mean inventing the fact it turns on. It arrives with the column, not
     * before it.
     */
    public static function guardFor(?Incident $incident): FileGuard
    {
        if (null === $incident) {
            return new FileGuard(
                GuardStateEnum::Locked,
                'No case file holds this evidence',
                'The key is one this module writes, but no incident on this platform records it. Nothing here can say whether it may be removed, so it is kept and left alone.',
            );
        }

        if ($incident->getStatus()->isOpen()) {
            return new FileGuard(
                GuardStateEnum::Locked,
                \sprintf('%s is still being worked', $incident->getReference()),
                \sprintf('This evidence is attached to an incident at "%s", and a claim still rests on it. While the case is open nothing may be taken off it — not by the person who uploaded it and not by anyone else. It can be removed once the case is resolved and filed.', $incident->getStatus()->label()),
            );
        }

        return new FileGuard(
            GuardStateEnum::Allowed,
            \sprintf('%s is resolved and filed', $incident->getReference()),
            'The case this evidence belongs to is finished, so the record no longer needs to hold on to it. Removing it takes the file off the case and leaves the case one line longer.',
        );
    }

    /**
     * One piece of evidence, as the hub knows it.
     *
     * Static and given its owner's URL rather than generating one, so the whole
     * mapping can be pinned against real entities without a router or a database
     * standing behind it.
     */
    public static function entryFor(IncidentEvidence $evidence, ?string $ownerUrl): FileEntry
    {
        $incident = $evidence->getIncident();
        $area = $incident->getArea();
        $kind = self::kindOf($evidence->getKind());

        return new FileEntry(
            key: (string) $evidence->getPath(),
            // The name the record kept, which for incidents IS the name a person
            // uploaded it under — unlike a patrol photograph, which is only ever
            // known by the key it was filed at.
            name: $evidence->getFilename(),
            // The DETECTED type where the blob was written and one was recorded;
            // the filename's extension otherwise, which is the only fact a
            // key-only row carries.
            mimeType: $evidence->getMimeType() ?? self::mimeTypeOf($evidence->getFilename()),
            // The measured size where a blob was written, and an honest zero
            // where none was: the hub's space bars must not add up bytes nobody
            // counted.
            byteSize: $evidence->getByteSize() ?? 0,
            // The RECORD's reference alone: the hub's own template prints
            // "{moduleLabel} · {ownerLabel}", so naming the module here would
            // print it twice.
            ownerLabel: $incident->getReference(),
            ownerUrl: $ownerUrl,
            moduleSlug: self::SLUG,
            moduleLabel: self::LABEL,
            areaSlug: $area->getUuidString(),
            areaLabel: $area->getName(),
            // The HANDSET's own moment where there is one. A document has no
            // such clock and sits under the day it was filed.
            takenAt: $evidence->getCapturedAt(),
            arrivedAt: $evidence->getCreatedAt(),
            // The preview beside the original where one was made; null otherwise.
            thumbKey: $evidence->getThumbKey(),
            // A photograph with a preview is Made (derived from the thumb key); a
            // photograph still without one is Waiting, not Failed — a key-only row
            // never went through the thumbnailer, so nothing on this machine tried
            // and could not. A document has nothing to shrink and derives Nothing.
            thumbState: FileKindEnum::Photo === $kind && null === $evidence->getThumbKey() ? ThumbStateEnum::Waiting : null,
            // The caption belongs to the record. The hub shows it; it is edited
            // on the case file and nowhere else.
            caption: $evidence->getCaption(),
            // THE RECORD'S OWN CLASSIFICATION, never a guess at the bytes: a
            // scanned claim form is a document even though its bytes are a JPEG,
            // because what the record keeps it for is what it is.
            kind: $kind,
        );
    }

    /**
     * Incidents' two kinds, in the hub's three. Track is the hub's third and is
     * not one of ours: an incident attaches evidence, never a route.
     */
    private static function kindOf(EvidenceKindEnum $kind): FileKindEnum
    {
        return match ($kind) {
            EvidenceKindEnum::Photo => FileKindEnum::Photo,
            EvidenceKindEnum::Document => FileKindEnum::Document,
        };
    }

    /**
     * The type read off the filename — the fallback for a row that carries no
     * detected type ({@see entryFor()} prefers the recorded one where a blob was
     * written). The extension is the only fact a key-only row has, so it is read,
     * and anything unrecognised says so rather than picking a plausible type. The
     * kind never depends on this (see {@see kindOf()}), which is what keeps a
     * misleading extension from moving a file into the wrong chip on the hub.
     */
    private static function mimeTypeOf(string $filename): string
    {
        return match (mb_strtolower(pathinfo($filename, \PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    /**
     * The case file's own page, or null where the area has no public address yet
     * (an area that was never persisted). A named-but-unlinked file is a state
     * the hub draws; a URL built from a null is a crash on every tile.
     */
    private function caseFileUrl(Incident $incident): ?string
    {
        $areaUuid = $incident->getArea()->getUuidString();

        if (null === $areaUuid) {
            return null;
        }

        return $this->urls->generate('incident_show', [
            'uuid' => $areaUuid,
            'reference' => $incident->getReference(),
        ]);
    }
}
