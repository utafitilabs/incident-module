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

namespace Uhifadhi\Incident\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Incident\Entity\Trait\TimestampableTrait;
use Uhifadhi\Incident\Enum\IncidentSeverityEnum;
use Uhifadhi\Incident\Enum\IncidentSourceEnum;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Repository\IncidentRepository;

/**
 * ONE EVENT, IN ONE AREA, AT ONE PLACE, IN ONE CATEGORY, AT ONE POINT IN A
 * FIVE-STATE WORKFLOW — the design's model card (IN·M1), as a table.
 *
 * Everything else hangs off it and each hanging thing exists for a stated reason:
 *
 *  - a {@see IncidentEvent} TIMELINE that is APPEND-ONLY. Nothing on it is ever
 *    edited or removed; a correction is a new event saying what was corrected,
 *    which is what makes the record worth anything in a hearing.
 *  - {@see IncidentEvidence} that keeps ITS OWN timestamps — the moment the
 *    handset recorded, never the moment somebody uploaded.
 *  - {@see IncidentParty} rows: a suspect, a claimant, a witness, the ranger who
 *    filed it and the ANIMAL are one shape wearing different roles.
 *  - {@see IncidentMoney}, and ONLY where the sub-category says so. A category
 *    that carries no money has no money card — absent, not greyed out.
 *
 * PROVENANCE IS WRITTEN ONCE AND NEVER EDITED. {@see recordProvenance()} refuses
 * a second call. An incident filed from a patrol observation stays linked to that
 * observation forever; the hand-off is deliberately a UUID and a label rather than a
 * foreign key, because the patrols module is a separate bundle and a host may
 * install either without the other.
 *
 * DEPARTMENT IS RECORDED, NEVER RESTRICTING. {@see $filedForDepartmentId} is the
 * lens the filer was looking through, kept so a performance page can say who was
 * working. It is not consulted by any read: incidents belong to the AREA, and a
 * department view is a reading of them.
 */
#[ORM\Entity(repositoryClass: IncidentRepository::class)]
#[ORM\Table(name: 'incident')]
// Indexes are declared by FIELD, never by column: a host chooses its own
// naming strategy (uhifadhi uses underscore), and a hard-coded column name here
// would build on one host and fail to build on another.
#[ORM\Index(name: 'idx_incident_area_status', fields: ['area', 'status'])]
#[ORM\Index(name: 'idx_incident_reported_at', fields: ['reportedAt'])]
#[ORM\HasLifecycleCallbacks]
class Incident
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    /**
     * The number people say out loud: INC-0313. Unique across the deployment
     * rather than per area, because it is quoted in radio traffic and on paper
     * where nobody repeats which area they meant.
     */
    #[ORM\Column(length: 24, unique: true)]
    private string $reference;

    /** ONE area. An incident that happened in two places is two incidents. */
    #[ORM\ManyToOne(targetEntity: AreaOfInterest::class)]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    /**
     * The area's own word for what happened. The join column is
     * `taxonomy_subcategory_id`: the column the installation-wide model used is
     * still on the table for one release, holding the answer this one was
     * migrated from.
     */
    #[ORM\ManyToOne(targetEntity: TaxonomySubcategory::class)]
    #[ORM\JoinColumn(name: 'taxonomy_subcategory_id', nullable: false, onDelete: 'RESTRICT')]
    private TaxonomySubcategory $subcategory;

    #[ORM\Column(length: 200)]
    private string $title;

    /**
     * THE REPORT AS IT WAS GIVEN, verbatim. Kept apart from {@see $assessment} on
     * purpose: two voices, two fields — one of them is evidence and the other is
     * judgement, and a single "description" would let a hearing read an officer's
     * opinion as a witness's words.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $narrative = null;

    /** The officer's own reading. See {@see $narrative}. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $assessment = null;

    /** ONE place, as GeoJSON Point text. Every incident has one; "somewhere in the area" is not a location. */
    #[ORM\Column(type: 'point')]
    private string $position;

    /**
     * The host's zone this point falls in, or NULL. Unzoned is a FIRST-CLASS
     * answer — an org that has drawn no zones is the normal state, and the "by
     * zone" widget says "unzoned" rather than hiding the row.
     */
    #[ORM\ManyToOne(targetEntity: Zone::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Zone $zone = null;

    #[ORM\Column(enumType: IncidentStatusEnum::class, options: ['default' => 'reported'])]
    private IncidentStatusEnum $status = IncidentStatusEnum::Reported;

    #[ORM\Column(enumType: IncidentSeverityEnum::class, options: ['default' => 'moderate'])]
    private IncidentSeverityEnum $severity = IncidentSeverityEnum::Moderate;

    /** The badge, never a state — see {@see IncidentSourceEnum}. */
    #[ORM\Column(enumType: IncidentSourceEnum::class, options: ['default' => 'direct'])]
    private IncidentSourceEnum $source = IncidentSourceEnum::Direct;

    /** When it HAPPENED, which is rarely when it was filed and is sometimes unknown. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $occurredAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $reportedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $respondedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /**
     * WHO RECORDED IT. The department KPI contribution point slices by the position this person
     * holds, so a null here is a row no department can claim — which is the honest
     * answer for a seeded or imported incident.
     */
    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?UserInterface $reportedBy = null;

    /** Whose queue it sits in. */
    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?UserInterface $assignedTo = null;

    /**
     * Recorded, never restricting — see the class docblock.
     *
     * AN ID AND NOT A RELATION, for the same reason the provenance hand-off above is
     * a uuid and not a foreign key: NO PACKAGE PUBLISHES A CONTRACT FOR A
     * DEPARTMENT. There is no `DepartmentInterface` in uhifadhi/contracts
     * and none in TeamBundle, so a `ManyToOne` here would name
     * somebody's class and make every installation that records an incident
     * hard-require the module that owns it. The fleet's rule for a department is
     * to walk the mapping and never the type — {@see \Uhifadhi\Contracts\Kpi\DepartmentRef}
     * is the same decision made one layer up — and by the time the lens reaches
     * a column it is one integer.
     */
    #[ORM\Column(nullable: true)]
    private ?int $filedForDepartmentId = null;

    /**
     * PROVENANCE — the record this incident was filed FROM, if any. Written once
     * by {@see recordProvenance()} and never again.
     *
     * @see recordProvenance()
     */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $sourceRecordUuid = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $sourceRecordLabel = null;

    /**
     * Where to go to see it. A URL rather than a route name: the module that filed
     * the incident knows its own addresses, and this bundle must not name another
     * bundle's routes to render a link back.
     */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $sourceRecordUrl = null;

    /**
     * THE ANSWERS TO THE QUESTIONS THE SUB-CATEGORY'S BEHAVIOUR BLOCKS ASK, kept
     * per block, in the shape the block asks in: a block whose questions are asked
     * once keeps `{key: answer}`, and a block that is a row the filer adds to keeps
     * `{rows: [{key: answer}, …]}`. A block the sub-category does not switch on has
     * nothing here, so unticking a block never leaves a ghost on an old record.
     *
     * @see \Uhifadhi\Incident\Model\BlockQuestionCatalogue  what each block asks
     *
     * @var array<string, array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $blockAnswers = [];

    /**
     * WHAT WAS CLAIMED — OR ASSESSED — AT FILING, in whole units, or null where
     * the money block asked nothing.
     *
     * THIS IS NOT THE MONEY RECORD. {@see $money} is opened by whoever assesses or
     * approves, in the state the money flow names; this is the figure the claimant
     * or the officer gave at the roadside, before anybody had judged it. Keeping
     * them apart is the whole point: the claimant is standing there with a number,
     * and throwing it away to ask again a week later is worse than recording it
     * unjudged — but recording it as an assessment would make a filer the judge.
     */
    #[ORM\Column(nullable: true)]
    private ?int $claimedAtFiling = null;

    /** @var Collection<int, IncidentEvent> */
    #[ORM\OneToMany(targetEntity: IncidentEvent::class, mappedBy: 'incident', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['occurredAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $events;

    /** @var Collection<int, IncidentEvidence> */
    #[ORM\OneToMany(targetEntity: IncidentEvidence::class, mappedBy: 'incident', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['capturedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $evidence;

    /** @var Collection<int, IncidentParty> */
    #[ORM\OneToMany(targetEntity: IncidentParty::class, mappedBy: 'incident', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $parties;

    /** @var Collection<int, IncidentLink> */
    #[ORM\OneToMany(targetEntity: IncidentLink::class, mappedBy: 'incident', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $links;

    /**
     * The money, or null where the sub-category carries none. ONE record, because
     * money runs in one direction per incident — the direction the sub-category
     * declares.
     */
    #[ORM\OneToOne(targetEntity: IncidentMoney::class, mappedBy: 'incident', cascade: ['persist', 'remove'])]
    private ?IncidentMoney $money = null;

    public function __construct(
        AreaOfInterest $area,
        TaxonomySubcategory $subcategory,
        string $reference,
        string $title,
        string $position,
        \DateTimeImmutable $reportedAt,
    ) {
        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->subcategory = $subcategory;
        $this->reference = $reference;
        $this->title = $title;
        $this->position = $position;
        $this->reportedAt = $reportedAt;
        $this->events = new ArrayCollection();
        $this->evidence = new ArrayCollection();
        $this->parties = new ArrayCollection();
        $this->links = new ArrayCollection();
        $this->initTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getArea(): AreaOfInterest
    {
        return $this->area;
    }

    public function getSubcategory(): TaxonomySubcategory
    {
        return $this->subcategory;
    }

    public function getKind(): TaxonomyKind
    {
        return $this->subcategory->getKind();
    }

    public function setSubcategory(TaxonomySubcategory $subcategory): static
    {
        $this->subcategory = $subcategory;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /**
     * WHAT THE REGISTER PRINTS — the first line of the title, and only that.
     *
     * The report form asks what happened on a TEXTAREA, because what a person
     * writes at the roadside is a sentence, not a database field, and a note
     * copied in from an observation is usually longer than one line's worth. Every
     * listing in this module is a row, though, and a row is one line high.
     *
     * So the field is comfortable and this is honest about the consequence: the
     * whole answer is stored and shown on the case file, and every list prints its
     * first line. Nothing is lost and nothing is silently reflowed.
     */
    public function headline(): string
    {
        $first = strtok($this->title, "\r\n");

        return false === $first ? $this->title : rtrim($first);
    }

    public function getNarrative(): ?string
    {
        return $this->narrative;
    }

    public function setNarrative(?string $narrative): static
    {
        $this->narrative = $narrative;

        return $this;
    }

    public function getAssessment(): ?string
    {
        return $this->assessment;
    }

    public function setAssessment(?string $assessment): static
    {
        $this->assessment = $assessment;

        return $this;
    }

    public function getPosition(): string
    {
        return $this->position;
    }

    public function setPosition(string $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getZone(): ?Zone
    {
        return $this->zone;
    }

    public function setZone(?Zone $zone): static
    {
        $this->zone = $zone;

        return $this;
    }

    /** The zone's name, or the design's word for a point in none of them. */
    public function zoneLabel(): string
    {
        return $this->zone?->getName() ?? 'unzoned';
    }

    public function getStatus(): IncidentStatusEnum
    {
        return $this->status;
    }

    /**
     * Deliberately package-blunt: this is the raw setter, and the ONLY supported
     * caller is {@see \Uhifadhi\Incident\Service\IncidentTransitionService},
     * which is where the guards live. A screen that moves an incident by calling
     * this directly has skipped verification, and the whole design says it cannot.
     */
    public function setStatus(IncidentStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getSeverity(): IncidentSeverityEnum
    {
        return $this->severity;
    }

    public function setSeverity(IncidentSeverityEnum $severity): static
    {
        $this->severity = $severity;

        return $this;
    }

    public function getSource(): IncidentSourceEnum
    {
        return $this->source;
    }

    public function setSource(IncidentSourceEnum $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getOccurredAt(): ?\DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(?\DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getReportedAt(): \DateTimeImmutable
    {
        return $this->reportedAt;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeImmutable $verifiedAt): static
    {
        $this->verifiedAt = $verifiedAt;

        return $this;
    }

    public function getRespondedAt(): ?\DateTimeImmutable
    {
        return $this->respondedAt;
    }

    public function setRespondedAt(?\DateTimeImmutable $respondedAt): static
    {
        $this->respondedAt = $respondedAt;

        return $this;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): static
    {
        $this->resolvedAt = $resolvedAt;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): static
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    public function getReportedBy(): ?UserInterface
    {
        return $this->reportedBy;
    }

    public function setReportedBy(?UserInterface $reportedBy): static
    {
        $this->reportedBy = $reportedBy;

        return $this;
    }

    public function getAssignedTo(): ?UserInterface
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?UserInterface $assignedTo): static
    {
        $this->assignedTo = $assignedTo;

        return $this;
    }

    public function getFiledForDepartmentId(): ?int
    {
        return $this->filedForDepartmentId;
    }

    public function setFiledForDepartmentId(?int $departmentId): static
    {
        $this->filedForDepartmentId = $departmentId;

        return $this;
    }

    /**
     * WRITE THE PARENTAGE, ONCE. A patrol observation filed as an incident keeps
     * that link forever, so this refuses a second call rather than quietly letting
     * a later import overwrite where the record came from.
     *
     * @throws \LogicException on any attempt to rewrite it
     */
    public function recordProvenance(Uuid $recordUuid, string $label, ?string $url = null): static
    {
        if (null !== $this->sourceRecordUuid) {
            throw new \LogicException(\sprintf('Incident %s already came from %s; provenance is written once and never edited.', $this->reference, (string) $this->sourceRecordLabel));
        }

        $this->sourceRecordUuid = $recordUuid;
        $this->sourceRecordLabel = $label;
        $this->sourceRecordUrl = $url;

        return $this;
    }

    public function getSourceRecordUuid(): ?Uuid
    {
        return $this->sourceRecordUuid;
    }

    public function getSourceRecordLabel(): ?string
    {
        return $this->sourceRecordLabel;
    }

    public function getSourceRecordUrl(): ?string
    {
        return $this->sourceRecordUrl;
    }

    public function hasProvenance(): bool
    {
        return null !== $this->sourceRecordUuid;
    }

    /**
     * @return array<string, array<string, mixed>> keyed by the block's wire value
     */
    public function getBlockAnswers(): array
    {
        return $this->blockAnswers;
    }

    /** @param array<string, array<string, mixed>> $blockAnswers */
    public function setBlockAnswers(array $blockAnswers): static
    {
        $this->blockAnswers = $blockAnswers;

        return $this;
    }

    public function getClaimedAtFiling(): ?int
    {
        return $this->claimedAtFiling;
    }

    public function setClaimedAtFiling(?int $claimedAtFiling): static
    {
        $this->claimedAtFiling = null === $claimedAtFiling ? null : max(0, $claimedAtFiling);

        return $this;
    }

    /** @return Collection<int, IncidentEvent> */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    public function addEvent(IncidentEvent $event): static
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
        }

        return $this;
    }

    /** @return Collection<int, IncidentEvidence> */
    public function getEvidence(): Collection
    {
        return $this->evidence;
    }

    public function addEvidence(IncidentEvidence $evidence): static
    {
        if (!$this->evidence->contains($evidence)) {
            $this->evidence->add($evidence);
        }

        return $this;
    }

    /**
     * A file leaves the case; the LINE saying it did does not. The timeline is
     * append-only, so a removal adds an event rather than erasing one.
     */
    public function removeEvidence(IncidentEvidence $evidence): static
    {
        $this->evidence->removeElement($evidence);

        return $this;
    }

    /** @return Collection<int, IncidentParty> */
    public function getParties(): Collection
    {
        return $this->parties;
    }

    public function addParty(IncidentParty $party): static
    {
        if (!$this->parties->contains($party)) {
            $this->parties->add($party);
        }

        return $this;
    }

    /** @return Collection<int, IncidentLink> */
    public function getLinks(): Collection
    {
        return $this->links;
    }

    public function addLink(IncidentLink $link): static
    {
        if (!$this->links->contains($link)) {
            $this->links->add($link);
        }

        return $this;
    }

    public function getMoney(): ?IncidentMoney
    {
        return $this->money;
    }

    public function setMoney(?IncidentMoney $money): static
    {
        $this->money = $money;

        return $this;
    }

    /** Whether this incident's sub-category carries money at all. */
    public function carriesMoney(): bool
    {
        return $this->subcategory->carriesMoney();
    }

    /** How long this has been open, from filing to resolution or to now. */
    public function ageInHours(\DateTimeImmutable $now): int
    {
        $end = $this->resolvedAt ?? $now;

        return max(0, intdiv($end->getTimestamp() - $this->reportedAt->getTimestamp(), 3600));
    }

    /**
     * Whether it has been open longer than its sub-category promised. A resolved
     * incident is judged on the time it TOOK, not on today's date, so a breach is
     * a permanent fact about the record rather than something that heals.
     */
    public function isPastTerm(\DateTimeImmutable $now): bool
    {
        return $this->ageInHours($now) > $this->subcategory->getTermHours();
    }

    /** Hours from filing to verification, or null while nobody has verified it. */
    public function hoursToVerify(): ?int
    {
        if (null === $this->verifiedAt) {
            return null;
        }

        return max(0, intdiv($this->verifiedAt->getTimestamp() - $this->reportedAt->getTimestamp(), 3600));
    }
}
