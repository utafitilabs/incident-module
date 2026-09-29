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

namespace Uhifadhi\Incident\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvent;
use Uhifadhi\Incident\Entity\IncidentEvidence;
use Uhifadhi\Incident\Enum\EvidenceKindEnum;
use Uhifadhi\Incident\Enum\IncidentEventKindEnum;
use Uhifadhi\Incident\Exception\IncidentEvidenceException;
use Uhifadhi\Incident\Repository\IncidentEvidenceRepository;
use Uhifadhi\Storage\Exception\EvidenceRejectedException;
use Uhifadhi\Storage\Model\StoredFile;
use Uhifadhi\Storage\Service\EvidenceStorage;

/**
 * THE ONLY WAY A PHOTOGRAPH OR A DOCUMENT GETS ONTO AN INCIDENT.
 *
 * ONE WAY IN, whatever sent the bytes — and it is {@see attach()}, which takes a
 * file that has ALREADY been stored. A browser upload reaches it through the
 * platform's upload target, which is handed the stored file by storage itself;
 * an importer or the seeder reaches it through {@see store()}, which writes
 * the blob first and then calls the same method. So the private storage, the
 * detected type, the measured size and the generated preview are identical
 * either way, and no path can store the same photograph twice.
 *
 * REMOVAL IS THE OTHER HALF, and it is {@see detach()}: the row goes, the case
 * keeps a line saying it went.
 *
 * THE BYTES ARE THE PLATFORM'S, THE ROW IS THIS MODULE'S. `EvidenceStorage`
 * validates against what the deployment accepts, writes into a storage outside
 * the document root, reads the type from the BYTES rather than believing a
 * filename, and tries for a ~400px preview. What this module owns is which case
 * file a file belongs to, what kind of thing it is, and what the handset
 * recorded — knowing that is what makes a module a module, and the hub is
 * designed never to know it.
 *
 * THE KEY PREFIX IS THE CONTRACT, and it is {@see IncidentEvidenceKey}, named
 * there and nowhere else. Three collaborators read it: this writer,
 * {@see \Uhifadhi\Incident\Security\IncidentEvidenceVoter}, which claims those
 * keys so storage does not deny them by default, and
 * {@see \Uhifadhi\Incident\Storage\IncidentFileSource}, which lists them on the
 * hub. A key written under any other prefix is a photograph nobody is allowed to
 * look at, on a page they are entitled to read — which fails silently, as a
 * broken image.
 *
 * TIME AND PLACE ARE THE HANDSET'S. `capturedAt` is when the photograph was
 * taken, never when it was uploaded; uploading is bookkeeping and the row's own
 * timestamps record it. Evidence copied in from a source record carries its
 * original values across rather than today's.
 */
final readonly class IncidentEvidenceService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EvidenceStorage $storage,
        private IncidentEvidenceRepository $evidence,
    ) {
    }

    /**
     * STORE A FILE AND ATTACH IT — the door for a caller that holds bytes and
     * nothing else: an importer, the seeder, a command.
     *
     * The upload component does NOT come through here. Storage has already
     * written the blob by the time a target's `received()` is called, so that
     * path goes straight to {@see attach()} and this method would store the same
     * photograph twice.
     *
     * @param \SplFileInfo $file      a plain File for an importer that never touched HTTP,
     *                                or an UploadedFile
     * @param string|null  $filename  what a person sees it called; the file's own name when
     *                                none is offered
     * @param string|null  $position  where it was taken, as GeoJSON Point text
     * @param string|null  $actorName who to print the timeline event under, kept beside the
     *                                account so the record still names them if the account goes
     *
     * @throws IncidentEvidenceException when the deployment does not accept the file — nothing
     *                                   is stored and no row is written
     */
    public function store(
        Incident $incident,
        \SplFileInfo $file,
        ?string $filename = null,
        ?string $caption = null,
        ?\DateTimeImmutable $capturedAt = null,
        ?string $position = null,
        ?\DateTimeImmutable $at = null,
        ?UserInterface $actor = null,
        ?string $actorName = null,
    ): IncidentEvidence {
        try {
            // Validation happens FIRST, inside store(): a refused file leaves no
            // blob, and returning before the row is constructed is what leaves no
            // record of one either.
            $stored = $this->storage->store(
                $file,
                IncidentEvidenceKey::prefixFor($incident),
                self::clientKey(),
            );
        } catch (EvidenceRejectedException $refused) {
            throw new IncidentEvidenceException($refused->getMessage(), previous: $refused);
        }

        // $filename stays as it came, null included: storage already recorded
        // what the file arrived called — the CLIENT's name for an upload, the
        // file's own for an importer — and second-guessing it here would print a
        // browser's temporary path where a person's filename belongs.
        return $this->attach($incident, $stored, $filename, $caption, $capturedAt, $position, $at, $actor, $actorName);
    }

    /**
     * WRITE THE CASE FILE'S ROW FOR A FILE THAT IS ALREADY STORED.
     *
     * THE BYTES ARE THE PLATFORM'S, THE ROW IS THIS MODULE'S, and this method is
     * exactly that line. It takes a {@see StoredFile} rather than a file on disk
     * because that is what the platform's upload target is handed: by the time
     * `received()` runs, the blob is in the private storage, under the prefix
     * this module named, with its type read off the bytes and its preview
     * already attempted. Re-storing it here would put the same photograph in the
     * deployment twice.
     *
     * @param string|null $filename  what a person sees it called; the name the file arrived
     *                               under when none is offered
     * @param string|null $position  where it was taken, as GeoJSON Point text
     * @param string|null $actorName who to print the timeline event under, kept beside the
     *                               account so the record still names them if the account goes
     */
    public function attach(
        Incident $incident,
        StoredFile $stored,
        ?string $filename = null,
        ?string $caption = null,
        ?\DateTimeImmutable $capturedAt = null,
        ?string $position = null,
        ?\DateTimeImmutable $at = null,
        ?UserInterface $actor = null,
        ?string $actorName = null,
    ): IncidentEvidence {
        $evidence = new IncidentEvidence($incident, self::kindOf($stored->mimeType), self::nameOf($stored, $filename))
            // The DETECTED type and the MEASURED size, so the hub weighs and
            // labels the bytes that are actually there.
            ->setPath($stored->key)
            ->setMimeType($stored->mimeType)
            ->setByteSize($stored->byteSize)
            // Null where nothing on this machine could decode the source. Stored
            // as null rather than as a key pointing at a file that is not there.
            ->setThumbKey($stored->thumbKey)
            ->setCapturedAt($capturedAt)
            ->setPosition($position)
            ->setCaption($caption);

        $this->entityManager->persist($evidence);

        new IncidentEvent(
            $incident,
            IncidentEventKindEnum::Evidence,
            $at ?? new \DateTimeImmutable(),
            \sprintf('%s attached: %s.', $evidence->getKind()->label(), $evidence->getFilename()),
        )->withActor($actor, $actorName);

        $this->entityManager->flush();

        return $evidence;
    }

    /**
     * TAKE ONE FILE BACK OFF ITS CASE FILE.
     *
     * THE ROW GOES, THE RECORD DOES NOT — it ends one line longer than it was.
     * That is the platform's whole promise about removal: what leaves is the
     * file, and the timeline says so, in the same append-only trail that carries
     * the attachment. A case file that could quietly lose a photograph is worth
     * nothing at the hearing it exists for, and one that lost a photograph
     * WITHOUT SAYING SO is worse than one that never had it.
     *
     * THE BYTES ARE NOT DELETED HERE, deliberately. The platform's upload
     * service calls this first and deletes the blob only once it returns, so a
     * refusal thrown from here leaves the file exactly where it was.
     *
     * @param string|null $actorName who to print the timeline event under, kept beside the
     *                               account so the record still names them if the account goes
     *
     * @throws IncidentEvidenceException when no case file on this platform holds that key
     */
    public function detach(
        string $key,
        ?\DateTimeImmutable $at = null,
        ?UserInterface $actor = null,
        ?string $actorName = null,
    ): void {
        $evidence = $this->evidence->findOneByPath($key);
        if (null === $evidence) {
            throw new IncidentEvidenceException('No case file on this platform holds that evidence.');
        }

        $incident = $evidence->getIncident();

        new IncidentEvent(
            $incident,
            IncidentEventKindEnum::Evidence,
            $at ?? new \DateTimeImmutable(),
            \sprintf('%s removed: %s.', $evidence->getKind()->label(), $evidence->getFilename()),
        )->withActor($actor, $actorName);

        $incident->removeEvidence($evidence);
        $this->entityManager->remove($evidence);
        $this->entityManager->flush();
    }

    /**
     * A fresh segment per file. Unique within the prefix is all storage asks for,
     * and a uuid is the only thing that answers it without a round trip.
     */
    private static function clientKey(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    /**
     * PHOTOGRAPH OR DOCUMENT, read from the DETECTED type. Never from the
     * filename: a filename is text somebody typed, and a case file that called a
     * spreadsheet a photograph would put it in the photograph strip.
     */
    private static function kindOf(string $mimeType): EvidenceKindEnum
    {
        return str_starts_with($mimeType, 'image/')
            ? EvidenceKindEnum::Photo
            : EvidenceKindEnum::Document;
    }

    /**
     * What it is called where a person can see it. Only a NAME is taken from the
     * upload — never a path, and never the extension that decides the stored key,
     * which storage derived from the bytes long before this ran.
     */
    private static function nameOf(StoredFile $stored, ?string $filename): string
    {
        $offered = trim((string) ($filename ?? $stored->clientName));

        return '' === $offered
            // Nothing offered a name at all — an importer reading a stream. The
            // key is the only thing such a file is known by, and saying so is
            // more honest than inventing something friendlier.
            ? mb_substr(basename($stored->key), 0, 160)
            : mb_substr(basename($offered), 0, 160);
    }
}
