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
use Uhifadhi\Incident\Entity\Trait\TimestampableTrait;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Repository\TaxonomySubcategoryRepository;

/**
 * A SUB-CATEGORY under one area's kind — the second (and last) level of the
 * area-scoped taxonomy the design rules (two levels, no deeper), and the one
 * thing an incident is filed against.
 *
 * WHAT A SUB-CATEGORY DECIDES is which of the platform's coded BEHAVIOUR BLOCKS
 * the filing form switches on for it ({@see $blocks}). Blocks are composed, not
 * chosen from a menu of types — see {@see BehaviorBlockEnum}. The set is this
 * area's business and reaches only the people filing incidents here.
 *
 * MONEY IS A BLOCK WITH A DIRECTION. When {@see BehaviorBlockEnum::Money} is in
 * the set, {@see $moneyDirection} says which way it runs; a null direction with
 * the block present means "not yet decided", and the block absent means the money
 * row is absent from the form entirely.
 *
 * THE CLOCK IS THIS ROW'S TOO. {@see $termHours} is what THIS word promises: a
 * human injury is 72 hours, a construction notice is 14 days, a compensation
 * claim is 30. One term for a whole area would be a lie about all three, which is
 * why the ageing widget reads the term off the row rather than off a setting.
 *
 * SO ARE THE QUESTIONS, AND THEY ARE THE BLOCKS'. The filing form asks under
 * this word exactly what the blocks in {@see $blocks} ask — a roadkill that
 * switches on species and a named place asks for species, sex, age class and the
 * road segment. There is no list of field names beside the blocks: a word that
 * could name its own fields could ask anything.
 *
 * WIRE-CODE, RETIREMENT, RENAMING — the same rules as {@see TaxonomyKind}: the
 * code never changes, retirement dims but keeps, nothing is deleted. Labels are
 * unique within the parent kind; codes are unique within the area.
 */
#[ORM\Entity(repositoryClass: TaxonomySubcategoryRepository::class)]
#[ORM\Table(name: 'incident_taxonomy_subcategory')]
#[ORM\HasLifecycleCallbacks]
class TaxonomySubcategory
{
    use TimestampableTrait;

    /** What a term reads as when an area has not promised one. */
    public const int DEFAULT_TERM_HOURS = 72;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: TaxonomyKind::class, inversedBy: 'subcategories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TaxonomyKind $kind;

    #[ORM\Column(length: 60)]
    private string $code;

    #[ORM\Column(length: 80)]
    private string $label;

    /**
     * The behaviour blocks this sub-category switches on, stored as their enum
     * values. The filing form renders exactly these, in this area, and nowhere
     * else.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $blocks = [];

    /**
     * Which way money runs when the Money block is on, or null for "not decided"
     * / no money block. Meaningful only alongside {@see BehaviorBlockEnum::Money}.
     */
    #[ORM\Column(enumType: MoneyDirectionEnum::class, nullable: true)]
    private ?MoneyDirectionEnum $moneyDirection = null;

    /** The term THIS word promises, in hours. See the class docblock. */
    #[ORM\Column(options: ['default' => self::DEFAULT_TERM_HOURS])]
    private int $termHours = self::DEFAULT_TERM_HOURS;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function __construct(TaxonomyKind $kind, string $code, string $label)
    {
        $this->uuid = Uuid::v7();
        $this->kind = $kind;
        $this->code = $code;
        $this->label = $label;
        $kind->addSubcategory($this);
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

    public function getKind(): TaxonomyKind
    {
        return $this->kind;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /** @return list<BehaviorBlockEnum> */
    public function getBlocks(): array
    {
        $blocks = [];
        foreach ($this->blocks as $value) {
            $block = BehaviorBlockEnum::tryFrom($value);
            if (null !== $block) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * Switch this sub-category's blocks to exactly this set, order preserved and
     * duplicates dropped — the whole set is replaced, never merged.
     *
     * @param list<BehaviorBlockEnum> $blocks
     */
    public function setBlocks(array $blocks): static
    {
        $values = [];
        foreach ($blocks as $block) {
            if (!\in_array($block->value, $values, true)) {
                $values[] = $block->value;
            }
        }
        $this->blocks = $values;

        // A set with no money block cannot carry a direction — clear it so a
        // stale direction can never linger on a form that has no money row.
        if (!$this->hasBlock(BehaviorBlockEnum::Money)) {
            $this->moneyDirection = null;
        }

        return $this;
    }

    public function hasBlock(BehaviorBlockEnum $block): bool
    {
        return \in_array($block->value, $this->blocks, true);
    }

    public function getMoneyDirection(): ?MoneyDirectionEnum
    {
        return $this->moneyDirection;
    }

    public function setMoneyDirection(?MoneyDirectionEnum $moneyDirection): static
    {
        $this->moneyDirection = $moneyDirection;

        return $this;
    }

    /** Whether the money block is switched on for this sub-category at all. */
    public function carriesMoney(): bool
    {
        return $this->hasBlock(BehaviorBlockEnum::Money);
    }

    public function getTermHours(): int
    {
        return $this->termHours;
    }

    public function setTermHours(int $termHours): static
    {
        $this->termHours = max(1, $termHours);

        return $this;
    }

    /**
     * The term as the design writes it on a chip: "72 h" under four days, "14 d"
     * beyond — nobody reads 336 h as a fortnight.
     */
    public function termLabel(): string
    {
        return $this->termHours < 96
            ? \sprintf('%d h', $this->termHours)
            : \sprintf('%d d', intdiv($this->termHours, 24));
    }

    /** "conflict › livestock depredation" — the path chip on the detail page. */
    public function path(): string
    {
        return $this->kind->getLabel().' › '.$this->label;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivate(): static
    {
        $this->active = false;

        return $this;
    }

    public function reactivate(): static
    {
        $this->active = true;

        return $this;
    }
}
