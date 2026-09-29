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

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Incident\Entity\Trait\TimestampableTrait;
use Uhifadhi\Incident\Enum\PartyRoleEnum;
use Uhifadhi\Incident\Repository\IncidentPartyRepository;

/**
 * SOMEBODY — OR SOMETHING — INVOLVED, wearing a role.
 *
 * ONE TABLE, NOT FOUR. A suspect, a claimant, a witness and the ranger who filed
 * it are the same shape of record; the design refuses to build a table per role
 * and so does this. The ANIMAL is a party too, which is what lets a repeat
 * offender be recognised across incidents instead of being retyped into a note.
 *
 * {@see $user} is set only when the party IS a person the deployment has an
 * account for — a ranger, a warden. A villager, a suspect and a lion are names
 * and descriptions, and forcing them into user rows would be a data-protection
 * problem dressed up as normalisation.
 */
#[ORM\Entity(repositoryClass: IncidentPartyRepository::class)]
#[ORM\Table(name: 'incident_party')]
class IncidentParty
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Incident::class, inversedBy: 'parties')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Incident $incident;

    #[ORM\Column(enumType: PartyRoleEnum::class)]
    private PartyRoleEnum $role;

    /** As they are named on the record: "N. Olesikari", "Lion · single adult, unmarked". */
    #[ORM\Column(length: 160)]
    private string $name;

    /** The line under the name: "household head · Riverside sub-village · +255 7…". */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $describedAs = null;

    /** The account, where the party is somebody the deployment has one for. */
    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?UserInterface $user = null;

    public function __construct(Incident $incident, PartyRoleEnum $role, string $name)
    {
        $this->uuid = Uuid::v7();
        $this->incident = $incident;
        $this->role = $role;
        $this->name = $name;
        $incident->addParty($this);
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

    public function getIncident(): Incident
    {
        return $this->incident;
    }

    public function getRole(): PartyRoleEnum
    {
        return $this->role;
    }

    public function setRole(PartyRoleEnum $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescribedAs(): ?string
    {
        return $this->describedAs;
    }

    public function setDescribedAs(?string $describedAs): static
    {
        $this->describedAs = $describedAs;

        return $this;
    }

    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    public function setUser(?UserInterface $user): static
    {
        $this->user = $user;

        return $this;
    }

    /**
     * The two letters the avatar disc prints. An animal has no initials, so it
     * gets the design's em dash rather than a nonsense monogram.
     */
    public function initials(): string
    {
        return self::initialsOf($this->role, $this->name);
    }

    /** The same rule for a party written down anywhere else on the record. */
    public static function initialsOf(PartyRoleEnum $role, string $name): string
    {
        if (PartyRoleEnum::Animal === $role) {
            return "\u{2014}";
        }

        $letters = '';
        foreach (preg_split('/[\s.]+/', $name, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $letters .= mb_strtoupper(mb_substr($word, 0, 1));
            if (2 === mb_strlen($letters)) {
                break;
            }
        }

        return '' !== $letters ? $letters : "\u{2014}";
    }
}
