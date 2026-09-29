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

namespace Uhifadhi\Incident\Devkit;

use Uhifadhi\Incident\Entity\TaxonomySubcategory;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Model\BlockAnswers;

/**
 * THE DESIGN'S SAMPLE MONTH, as data — the forty-seven incidents every widget in
 * the preset gallery repeats the numbers of.
 *
 * The gallery states them once and this table reproduces them EXACTLY:
 *
 *   47 filed · 31 still open (7 reported · 13 verified · 11 in progress)
 *   16 reached resolved · 5 closed
 *   18 conflict · 12 poaching · 9 compliance · 8 mortality
 *   TZS 8,450,000 assessed in fines, 5,900,000 collected
 *   TZS 12,400,000 claimed in compensation, 9,200,000 approved, 4,700,000 paid
 *   seven zones
 *
 * {@see \Uhifadhi\Incident\Tests\Unit\Devkit\SeedMonthTest} adds those columns
 * up and fails if a row ever drifts, because a seed that quietly stopped matching
 * the spec is worse than no seed: every screenshot in the gallery would be a
 * claim the product no longer supports.
 *
 * THIRTEEN OF THE ROWS ARE THE DESIGN'S OWN, by reference and to the word —
 * INC-0306 to INC-0318 are the incidents the register, the queue, the ageing
 * widget and the worked case file all name. The other thirty-four fill the month
 * out to the stated totals; they are ordinary conservation vocabulary and never a
 * real deployment's records.
 *
 * ONE DEPARTURE FROM THE DESIGN, and it is flagged rather than hidden: the
 * register draws INC-0317 under a sub-category called "predator presence", which
 * the reference card's sixteen do not include. The card is the model spec, so the
 * row is filed under `livestock-depredation` here. Whether "predator presence"
 * becomes a seventeenth sub-category is a ruling nobody has made.
 */
final class SeedMonth
{
    /** The department names the sample month's kinds lead with, in the design's own words. */
    public const string PROTECTION = 'Protection Service';
    public const string ECOLOGY = 'Ecology & Wildlife Mgmt';

    /**
     * THE VOCABULARY THE SAMPLE MONTH IS FILED AGAINST — the design's reference
     * card (IN·09), as data: four kinds and the sixteen sub-categories under
     * them, the departments each one's lens leads with, which
     * way money runs on it, what it promises and which BEHAVIOUR BLOCKS it
     * switches on — which is the whole of what its form asks.
     *
     * THE BLOCKS THE DESIGN NAMES ARE THE DESIGN'S: snaring carries Species,
     * Counts, Method & means, Seizures and Money · fine, which is the sixteen
     * questions the report page counts; livestock depredation carries Species,
     * Counts, Parties and Money · compensation; crop raiding carries the money
     * block alone, which is the smallest step 2 a word can produce. The other
     * thirteen are configured the way this seed's words were already being asked
     * about, so every one of the twelve blocks appears somewhere a developer can
     * see it.
     *
     * IT IS SEED CONTENT, NOT A DEFAULT. The module ships no taxonomy and seeds
     * none: a new area starts empty and writes its own words in the kinds editor.
     * This table exists so that `fixtures:seed` produces an area whose editor,
     * register and dashboard all agree, and it reaches a database only through
     * devkit — which installs via `require-dev`.
     *
     * THREE RULINGS ARE WRITTEN INTO IT, and each is a decision that would
     * otherwise be argued again every time somebody reads the code:
     *
     *  1. **Roadkill is ONE entry that carries a FINE.** Not a pair of linked
     *     incidents, one ecological and one for the driver. A vehicle killing a
     *     zebra is one event; whether a driver was fined is a fact about that
     *     event, and the money field is how it is recorded.
     *  2. **Money is per SUB-category, never per kind.** Roadkill carries a fine
     *     while natural mortality beside it carries nothing, so the money block is
     *     absent from one form and present in the other.
     *  3. **Each sub-category promises its OWN term.** A human injury is 72 hours;
     *     a construction notice is 14 days; a compensation claim is 30. One global
     *     term would be a lie about all three.
     *
     * The conflict kind deliberately names BOTH departments in `leads`: it sits in
     * both lenses, and the design says so on the reference card.
     *
     * The key of each entry is the WIRE-CODE the row is created with, so the codes
     * the incident rows below name are the codes the seeded sub-categories hold.
     *
     * @return array<string, array{
     *     label: string,
     *     leads: list<string>,
     *     subcategories: array<string, array{label: string, money: string|null, term_hours: int, blocks: list<string>}>
     * }>
     */
    public static function kinds(): array
    {
        return [
            'poaching' => [
                'label' => 'Poaching & wildlife crime',
                'leads' => [self::PROTECTION],
                'subcategories' => [
                    'snaring' => [
                        'label' => 'snaring',
                        'money' => 'fine',
                        'term_hours' => 72,
                        'blocks' => ['species', 'counts', 'method', 'seizures', 'money'],
                    ],
                    'bushmeat' => [
                        'label' => 'bushmeat',
                        'money' => 'fine',
                        'term_hours' => 72,
                        'blocks' => ['species', 'counts', 'method', 'parties', 'seizures', 'money'],
                    ],
                    'ivory-trophy' => [
                        'label' => 'ivory & trophy',
                        'money' => 'fine',
                        'term_hours' => 72,
                        'blocks' => ['species', 'method', 'parties', 'seizures', 'money'],
                    ],
                    'illegal-fishing' => [
                        'label' => 'illegal fishing',
                        'money' => 'fine',
                        'term_hours' => 168,
                        'blocks' => ['counts', 'method', 'parties', 'seizures', 'named-place', 'money'],
                    ],
                ],
            ],
            'conflict' => [
                'label' => 'Human–wildlife conflict',
                // BOTH lenses lead with conflict — the design's reference card
                // prints "leads: Protection · Ecology" against this one row.
                'leads' => [self::PROTECTION, self::ECOLOGY],
                'subcategories' => [
                    'livestock-depredation' => [
                        'label' => 'livestock depredation',
                        'money' => 'compensation',
                        'term_hours' => 720,
                        'blocks' => ['species', 'counts', 'parties', 'money'],
                    ],
                    'crop-raiding' => [
                        'label' => 'crop raiding',
                        'money' => 'compensation',
                        'term_hours' => 72,
                        'blocks' => ['money'],
                    ],
                    'human-injury' => [
                        'label' => 'human injury',
                        'money' => 'compensation',
                        'term_hours' => 72,
                        'blocks' => ['species', 'casualty', 'named-place', 'money'],
                    ],
                    'property-damage' => [
                        'label' => 'property damage',
                        'money' => 'compensation',
                        'term_hours' => 336,
                        'blocks' => ['species', 'extent', 'named-place', 'money'],
                    ],
                ],
            ],
            'compliance' => [
                'label' => 'Compliance & encroachment',
                'leads' => [self::PROTECTION],
                'subcategories' => [
                    'unauthorized-construction' => [
                        'label' => 'unauthorized construction',
                        'money' => 'fine',
                        'term_hours' => 336,
                        'blocks' => ['counts', 'extent', 'parties', 'notice', 'money'],
                    ],
                    'illegal-grazing' => [
                        'label' => 'illegal grazing',
                        'money' => 'fine',
                        'term_hours' => 336,
                        'blocks' => ['counts', 'extent', 'parties', 'notice', 'money'],
                    ],
                    'boundary-encroachment' => [
                        'label' => 'boundary encroachment',
                        'money' => 'fine',
                        'term_hours' => 336,
                        'blocks' => ['extent', 'named-place', 'parties', 'notice', 'money'],
                    ],
                    'unlicensed-operation' => [
                        'label' => 'unlicensed operation',
                        'money' => 'fine',
                        'term_hours' => 336,
                        'blocks' => ['counts', 'method', 'parties', 'notice', 'money'],
                    ],
                ],
            ],
            'mortality' => [
                'label' => 'Wildlife mortality',
                'leads' => [self::ECOLOGY],
                'subcategories' => [
                    // THE ROADKILL RULING, as one row: one entry, and it may carry
                    // a fine. See the class docblock.
                    'roadkill' => [
                        'label' => 'roadkill',
                        'money' => 'fine',
                        'term_hours' => 168,
                        'blocks' => ['species', 'condition', 'named-place', 'money'],
                    ],
                    'natural-mortality' => [
                        'label' => 'natural mortality',
                        'money' => null,
                        'term_hours' => 168,
                        'blocks' => ['species', 'condition'],
                    ],
                    'disease-die-off' => [
                        'label' => 'disease die-off',
                        'money' => null,
                        'term_hours' => 72,
                        'blocks' => ['species', 'counts', 'samples', 'condition'],
                    ],
                    'poisoning' => [
                        'label' => 'poisoning',
                        'money' => null,
                        'term_hours' => 168,
                        'blocks' => ['species', 'counts', 'method', 'samples', 'condition'],
                    ],
                ],
            ],
        ];
    }

    /**
     * HOW FAR BACK THE SAMPLE REACHES, in days, ending TODAY.
     *
     * The month is a SHAPE, not a date. Seeded at a fixed 2026-08 it landed
     * entirely outside the dashboard's default window — the current month — so a
     * freshly seeded installation opened on "0 filed" and an empty register, with
     * forty-seven incidents sitting just out of view. A seed whose first screen is
     * empty is worse than no seed.
     */
    public const int SPAN_DAYS = 42;

    /**
     * How many of the forty-seven land inside the CURRENT calendar month.
     *
     * Most of them, deliberately: the dashboard opens on this month, and a seed
     * that put its weight in the weeks before it would be showing the product
     * looking quiet. The rest fill the run-up so the trend charts have a slope to
     * draw and the ageing widget has something old in it.
     */
    public const int RECENT_COUNT = 28;

    /** How far back the recent group may start when the month is already long. */
    private const int RECENT_MAX_DAYS = 21;

    /**
     * The seed PostGIS scatters the sample month's points with.
     *
     * WHERE the points are is the installation's boundary's business and no
     * table's — see {@see IncidentContentProvider}.
     * All that lives here is the seed, so two runs against the same area put the
     * same incident in the same place and a screenshot keeps meaning something.
     */
    public const int RANDOM_SEED = 20_260_822;

    /** The seven zones the gallery names. Attached only where the host has drawn them. */
    public const array ZONES = ['North Gate', 'Acacia Wood', 'South Gate', 'Highland Ward', 'Salt Pan', 'Spring Basin', 'West Plains'];

    /**
     * The forty-seven, oldest first — which is also reference order, because a
     * case number is issued when the report is taken.
     *
     * @return list<array{
     *     reference: string, day: int, hour: int, minute: int,
     *     subcategory: string, status: string, severity: string, zone: string,
     *     title: string, narrative: string|null, source: string,
     *     money: array{claimed: int|null, assessed: int|null, approved: int|null, settled: int|null}|null,
     *     evidence: int,
     *     parties: list<array{role: string, name: string, described: string|null}>
     * }>
     */
    public static function incidents(): array
    {
        return [
            [
                'reference' => 'INC-0272',
                'day' => 1,
                'hour' => 6,
                'minute' => 0,
                'subcategory' => 'livestock-depredation',
                'status' => 'closed',
                'severity' => 'low',
                'zone' => 'North Gate',
                'title' => 'Predator took livestock at a boma near North Gate — 1 head lost',
                'narrative' => null,
                'source' => 'radio',
                'money' => [
                    'claimed' => 330_000,
                    'assessed' => 250_000,
                    'approved' => 250_000,
                    'settled' => 250_000,
                ],
                'evidence' => 0,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0273',
                'day' => 1,
                'hour' => 7,
                'minute' => 7,
                'subcategory' => 'bushmeat',
                'status' => 'closed',
                'severity' => 'moderate',
                'zone' => 'Acacia Wood',
                'title' => 'Dried bushmeat seized at the Acacia Wood checkpoint — 2 kg',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 150_000,
                    'approved' => 150_000,
                    'settled' => 150_000,
                ],
                'evidence' => 1,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0274',
                'day' => 1,
                'hour' => 8,
                'minute' => 14,
                'subcategory' => 'human-injury',
                'status' => 'closed',
                'severity' => 'high',
                'zone' => 'South Gate',
                'title' => 'Herder injured by wildlife near South Gate — 3 hospitalised',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => 370_000,
                    'assessed' => 280_000,
                    'approved' => 280_000,
                    'settled' => 280_000,
                ],
                'evidence' => 2,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0275',
                'day' => 2,
                'hour' => 9,
                'minute' => 21,
                'subcategory' => 'unlicensed-operation',
                'status' => 'closed',
                'severity' => 'moderate',
                'zone' => 'Highland Ward',
                'title' => 'Unlicensed tour operation working out of Highland Ward — 4 vehicles',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 200_000,
                    'approved' => 200_000,
                    'settled' => 200_000,
                ],
                'evidence' => 0,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0276',
                'day' => 2,
                'hour' => 10,
                'minute' => 28,
                'subcategory' => 'livestock-depredation',
                'status' => 'closed',
                'severity' => 'low',
                'zone' => 'Salt Pan',
                'title' => 'Predator took livestock at a boma near Salt Pan — 5 head lost',
                'narrative' => null,
                'source' => 'radio',
                'money' => [
                    'claimed' => 410_000,
                    'assessed' => 310_000,
                    'approved' => 310_000,
                    'settled' => 310_000,
                ],
                'evidence' => 1,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0277',
                'day' => 2,
                'hour' => 11,
                'minute' => 35,
                'subcategory' => 'natural-mortality',
                'status' => 'verified',
                'severity' => 'high',
                'zone' => 'Spring Basin',
                'title' => 'Carcass found near Spring Basin, no injury pattern — 1 recorded',
                'narrative' => null,
                'source' => 'direct',
                'money' => null,
                'evidence' => 2,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0278',
                'day' => 3,
                'hour' => 12,
                'minute' => 42,
                'subcategory' => 'ivory-trophy',
                'status' => 'resolved',
                'severity' => 'moderate',
                'zone' => 'West Plains',
                'title' => 'Trophy pieces recovered near West Plains — 2 items',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 250_000,
                    'approved' => 250_000,
                    'settled' => 250_000,
                ],
                'evidence' => 0,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0279',
                'day' => 3,
                'hour' => 13,
                'minute' => 49,
                'subcategory' => 'property-damage',
                'status' => 'resolved',
                'severity' => 'moderate',
                'zone' => 'North Gate',
                'title' => 'Water tank and fencing wrecked at North Gate — 3 structures',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => 450_000,
                    'assessed' => 340_000,
                    'approved' => 340_000,
                    'settled' => 340_000,
                ],
                'evidence' => 1,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0280',
                'day' => 3,
                'hour' => 14,
                'minute' => 56,
                'subcategory' => 'livestock-depredation',
                'status' => 'resolved',
                'severity' => 'high',
                'zone' => 'Acacia Wood',
                'title' => 'Predator took livestock at a boma near Acacia Wood — 4 head lost',
                'narrative' => null,
                'source' => 'radio',
                'money' => [
                    'claimed' => 490_000,
                    'assessed' => 370_000,
                    'approved' => 370_000,
                    'settled' => 370_000,
                ],
                'evidence' => 2,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0281',
                'day' => 4,
                'hour' => 15,
                'minute' => 3,
                'subcategory' => 'bushmeat',
                'status' => 'resolved',
                'severity' => 'low',
                'zone' => 'South Gate',
                'title' => 'Dried bushmeat seized at the South Gate checkpoint — 5 kg',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 300_000,
                    'approved' => 300_000,
                    'settled' => 300_000,
                ],
                'evidence' => 0,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0282',
                'day' => 4,
                'hour' => 16,
                'minute' => 10,
                'subcategory' => 'human-injury',
                'status' => 'resolved',
                'severity' => 'low',
                'zone' => 'Highland Ward',
                'title' => 'Herder injured by wildlife near Highland Ward — 1 hospitalised',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => 530_000,
                    'assessed' => 400_000,
                    'approved' => 400_000,
                    'settled' => 400_000,
                ],
                'evidence' => 1,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0283',
                'day' => 4,
                'hour' => 6,
                'minute' => 17,
                'subcategory' => 'unlicensed-operation',
                'status' => 'resolved',
                'severity' => 'moderate',
                'zone' => 'Salt Pan',
                'title' => 'Unlicensed tour operation working out of Salt Pan — 2 vehicles',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 350_000,
                    'approved' => 350_000,
                    'settled' => 350_000,
                ],
                'evidence' => 2,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0284',
                'day' => 5,
                'hour' => 7,
                'minute' => 24,
                'subcategory' => 'livestock-depredation',
                'status' => 'resolved',
                'severity' => 'high',
                'zone' => 'Spring Basin',
                'title' => 'Predator took livestock at a boma near Spring Basin — 3 head lost',
                'narrative' => null,
                'source' => 'radio',
                'money' => [
                    'claimed' => 570_000,
                    'assessed' => 430_000,
                    'approved' => 430_000,
                    'settled' => 430_000,
                ],
                'evidence' => 0,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0285',
                'day' => 5,
                'hour' => 8,
                'minute' => 31,
                'subcategory' => 'natural-mortality',
                'status' => 'resolved',
                'severity' => 'moderate',
                'zone' => 'West Plains',
                'title' => 'Carcass found near West Plains, no injury pattern — 4 recorded',
                'narrative' => null,
                'source' => 'direct',
                'money' => null,
                'evidence' => 1,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0286',
                'day' => 5,
                'hour' => 9,
                'minute' => 38,
                'subcategory' => 'ivory-trophy',
                'status' => 'in_progress',
                'severity' => 'low',
                'zone' => 'North Gate',
                'title' => 'Trophy pieces recovered near North Gate — 5 items',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 400_000,
                    'approved' => 400_000,
                    'settled' => 200_000,
                ],
                'evidence' => 2,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0287',
                'day' => 6,
                'hour' => 10,
                'minute' => 45,
                'subcategory' => 'property-damage',
                'status' => 'in_progress',
                'severity' => 'high',
                'zone' => 'Acacia Wood',
                'title' => 'Water tank and fencing wrecked at Acacia Wood — 1 structures',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => 1_110_000,
                    'assessed' => 1_060_000,
                    'approved' => 1_060_000,
                    'settled' => 0,
                ],
                'evidence' => 0,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0288',
                'day' => 6,
                'hour' => 11,
                'minute' => 52,
                'subcategory' => 'livestock-depredation',
                'status' => 'in_progress',
                'severity' => 'moderate',
                'zone' => 'South Gate',
                'title' => 'Predator took livestock at a boma near South Gate — 2 head lost',
                'narrative' => null,
                'source' => 'radio',
                'money' => [
                    'claimed' => 650_000,
                    'assessed' => 490_000,
                    'approved' => 490_000,
                    'settled' => 0,
                ],
                'evidence' => 1,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0289',
                'day' => 6,
                'hour' => 12,
                'minute' => 59,
                'subcategory' => 'bushmeat',
                'status' => 'in_progress',
                'severity' => 'moderate',
                'zone' => 'Highland Ward',
                'title' => 'Dried bushmeat seized at the Highland Ward checkpoint — 3 kg',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 550_000,
                    'approved' => 550_000,
                    'settled' => 100_000,
                ],
                'evidence' => 2,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0290',
                'day' => 7,
                'hour' => 13,
                'minute' => 6,
                'subcategory' => 'human-injury',
                'status' => 'in_progress',
                'severity' => 'critical',
                'zone' => 'Salt Pan',
                'title' => 'Herder injured by wildlife near Salt Pan — 4 hospitalised',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => 330_000,
                    'assessed' => 250_000,
                    'approved' => 250_000,
                    'settled' => 0,
                ],
                'evidence' => 0,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0291',
                'day' => 7,
                'hour' => 14,
                'minute' => 13,
                'subcategory' => 'unlicensed-operation',
                'status' => 'in_progress',
                'severity' => 'low',
                'zone' => 'Spring Basin',
                'title' => 'Unlicensed tour operation working out of Spring Basin — 5 vehicles',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 150_000,
                    'approved' => 150_000,
                    'settled' => 150_000,
                ],
                'evidence' => 1,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0292',
                'day' => 7,
                'hour' => 15,
                'minute' => 20,
                'subcategory' => 'livestock-depredation',
                'status' => 'verified',
                'severity' => 'low',
                'zone' => 'West Plains',
                'title' => 'Predator took livestock at a boma near West Plains — 1 head lost',
                'narrative' => null,
                'source' => 'radio',
                'money' => [
                    'claimed' => 370_000,
                    'assessed' => 280_000,
                    'approved' => 280_000,
                    'settled' => 280_000,
                ],
                'evidence' => 2,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0293',
                'day' => 8,
                'hour' => 16,
                'minute' => 27,
                'subcategory' => 'natural-mortality',
                'status' => 'verified',
                'severity' => 'moderate',
                'zone' => 'North Gate',
                'title' => 'Carcass found near North Gate, no injury pattern — 2 recorded',
                'narrative' => null,
                'source' => 'direct',
                'money' => null,
                'evidence' => 0,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0294',
                'day' => 8,
                'hour' => 6,
                'minute' => 34,
                'subcategory' => 'ivory-trophy',
                'status' => 'verified',
                'severity' => 'critical',
                'zone' => 'Acacia Wood',
                'title' => 'Trophy pieces recovered near Acacia Wood — 3 items',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 200_000,
                    'approved' => 200_000,
                    'settled' => 200_000,
                ],
                'evidence' => 1,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0295',
                'day' => 8,
                'hour' => 7,
                'minute' => 41,
                'subcategory' => 'property-damage',
                'status' => 'verified',
                'severity' => 'moderate',
                'zone' => 'South Gate',
                'title' => 'Water tank and fencing wrecked at South Gate — 4 structures',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => 410_000,
                    'assessed' => 310_000,
                    'approved' => 310_000,
                    'settled' => 310_000,
                ],
                'evidence' => 2,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0296',
                'day' => 9,
                'hour' => 8,
                'minute' => 48,
                'subcategory' => 'livestock-depredation',
                'status' => 'verified',
                'severity' => 'low',
                'zone' => 'Highland Ward',
                'title' => 'Predator took livestock at a boma near Highland Ward — 5 head lost',
                'narrative' => null,
                'source' => 'radio',
                'money' => [
                    'claimed' => 450_000,
                    'assessed' => 340_000,
                    'approved' => 340_000,
                    'settled' => 340_000,
                ],
                'evidence' => 0,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0297',
                'day' => 9,
                'hour' => 9,
                'minute' => 55,
                'subcategory' => 'bushmeat',
                'status' => 'verified',
                'severity' => 'high',
                'zone' => 'Salt Pan',
                'title' => 'Dried bushmeat seized at the Salt Pan checkpoint — 1 kg',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 250_000,
                    'approved' => 250_000,
                    'settled' => 250_000,
                ],
                'evidence' => 1,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0298',
                'day' => 9,
                'hour' => 10,
                'minute' => 2,
                'subcategory' => 'human-injury',
                'status' => 'verified',
                'severity' => 'moderate',
                'zone' => 'Spring Basin',
                'title' => 'Herder injured by wildlife near Spring Basin — 2 hospitalised',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => 1_430_000,
                    'assessed' => 1_390_000,
                    'approved' => 1_390_000,
                    'settled' => 1_390_000,
                ],
                'evidence' => 2,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0299',
                'day' => 10,
                'hour' => 11,
                'minute' => 9,
                'subcategory' => 'unlicensed-operation',
                'status' => 'verified',
                'severity' => 'moderate',
                'zone' => 'West Plains',
                'title' => 'Unlicensed tour operation working out of West Plains — 3 vehicles',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 300_000,
                    'approved' => 300_000,
                    'settled' => 300_000,
                ],
                'evidence' => 0,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0300',
                'day' => 10,
                'hour' => 12,
                'minute' => 16,
                'subcategory' => 'roadkill',
                'status' => 'reported',
                'severity' => 'high',
                'zone' => 'North Gate',
                'title' => 'Roadkill on the North Gate road — 4 carcass removed',
                'narrative' => null,
                'source' => 'radio',
                'money' => [
                    'claimed' => null,
                    'assessed' => 350_000,
                    'approved' => 350_000,
                    'settled' => 350_000,
                ],
                'evidence' => 1,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0301',
                'day' => 10,
                'hour' => 13,
                'minute' => 23,
                'subcategory' => 'bushmeat',
                'status' => 'reported',
                'severity' => 'low',
                'zone' => 'Acacia Wood',
                'title' => 'Dried bushmeat seized at the Acacia Wood checkpoint — 5 kg',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 400_000,
                    'approved' => 400_000,
                    'settled' => 400_000,
                ],
                'evidence' => 2,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0302',
                'day' => 11,
                'hour' => 14,
                'minute' => 30,
                'subcategory' => 'ivory-trophy',
                'status' => 'reported',
                'severity' => 'low',
                'zone' => 'South Gate',
                'title' => 'Trophy pieces recovered near South Gate — 1 items',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 450_000,
                    'approved' => 450_000,
                    'settled' => 450_000,
                ],
                'evidence' => 0,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0303',
                'day' => 11,
                'hour' => 15,
                'minute' => 37,
                'subcategory' => 'ivory-trophy',
                'status' => 'reported',
                'severity' => 'moderate',
                'zone' => 'Highland Ward',
                'title' => 'Trophy pieces recovered near Highland Ward — 2 items',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 150_000,
                    'approved' => 150_000,
                    'settled' => 150_000,
                ],
                'evidence' => 1,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0304',
                'day' => 12,
                'hour' => 16,
                'minute' => 44,
                'subcategory' => 'roadkill',
                'status' => 'reported',
                'severity' => 'high',
                'zone' => 'Salt Pan',
                'title' => 'Roadkill on the Salt Pan road — 3 carcass removed',
                'narrative' => null,
                'source' => 'radio',
                'money' => [
                    'claimed' => null,
                    'assessed' => 200_000,
                    'approved' => 200_000,
                    'settled' => 200_000,
                ],
                'evidence' => 2,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0305',
                'day' => 12,
                'hour' => 6,
                'minute' => 51,
                'subcategory' => 'illegal-grazing',
                'status' => 'reported',
                'severity' => 'moderate',
                'zone' => 'Spring Basin',
                'title' => 'Cattle grazing inside the Spring Basin line — 4 head',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 400_000,
                    'approved' => 400_000,
                    'settled' => 400_000,
                ],
                'evidence' => 0,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0306',
                'day' => 13,
                'hour' => 9,
                'minute' => 5,
                'subcategory' => 'poisoning',
                'status' => 'verified',
                'severity' => 'critical',
                'zone' => 'Salt Pan',
                'title' => 'Vulture die-off at a poisoned carcass — nine birds',
                'narrative' => null,
                'source' => 'radio',
                'money' => null,
                'evidence' => 3,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0307',
                'day' => 15,
                'hour' => 8,
                'minute' => 40,
                'subcategory' => 'illegal-grazing',
                'status' => 'resolved',
                'severity' => 'low',
                'zone' => 'West Plains',
                'title' => 'Cattle grazing inside the West Plains line — 60 head',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 300_000,
                    'approved' => 300_000,
                    'settled' => 300_000,
                ],
                'evidence' => 1,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0308',
                'day' => 16,
                'hour' => 22,
                'minute' => 10,
                'subcategory' => 'human-injury',
                'status' => 'in_progress',
                'severity' => 'critical',
                'zone' => 'North Gate',
                'title' => 'Hyena attack on a herder at night — hospitalised at North Gate',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => 2_000_000,
                    'assessed' => 1_500_000,
                    'approved' => 1_500_000,
                    'settled' => 0,
                ],
                'evidence' => 2,
                'parties' => [
                    [
                        'role' => 'claimant',
                        'name' => 'S. Ndarai',
                        'described' => 'herder · North Gate · treated at the health centre',
                    ],
                ],
            ],
            [
                'reference' => 'INC-0309',
                'day' => 17,
                'hour' => 10,
                'minute' => 25,
                'subcategory' => 'boundary-encroachment',
                'status' => 'in_progress',
                'severity' => 'moderate',
                'zone' => 'Highland Ward',
                'title' => 'Cultivation across the Highland Ward boundary — 3 acres',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 250_000,
                    'approved' => 250_000,
                    'settled' => 0,
                ],
                'evidence' => 1,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0310',
                'day' => 18,
                'hour' => 9,
                'minute' => 0,
                'subcategory' => 'illegal-grazing',
                'status' => 'resolved',
                'severity' => 'moderate',
                'zone' => 'South Gate',
                'title' => 'Cattle grazing inside the South Gate exclusion line — 240 head',
                'narrative' => null,
                'source' => 'patrol_observation',
                'money' => [
                    'claimed' => null,
                    'assessed' => 750_000,
                    'approved' => 750_000,
                    'settled' => 750_000,
                ],
                'evidence' => 2,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0311',
                'day' => 19,
                'hour' => 7,
                'minute' => 15,
                'subcategory' => 'bushmeat',
                'status' => 'in_progress',
                'severity' => 'high',
                'zone' => 'South Gate',
                'title' => 'Two suspects detained at South Gate gate with 34 kg of dried bushmeat',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 900_000,
                    'approved' => 900_000,
                    'settled' => 450_000,
                ],
                'evidence' => 2,
                'parties' => [
                    [
                        'role' => 'suspect',
                        'name' => 'Two men, detained',
                        'described' => 'held at South Gate gate · statements recorded',
                    ],
                ],
            ],
            [
                'reference' => 'INC-0312',
                'day' => 19,
                'hour' => 16,
                'minute' => 45,
                'subcategory' => 'crop-raiding',
                'status' => 'verified',
                'severity' => 'moderate',
                'zone' => 'Spring Basin',
                'title' => 'Elephant crop raid — 1.4 ha of maize destroyed overnight',
                'narrative' => null,
                'source' => 'community',
                'money' => [
                    'claimed' => 900_000,
                    'assessed' => null,
                    'approved' => null,
                    'settled' => 0,
                ],
                'evidence' => 2,
                'parties' => [
                    [
                        'role' => 'claimant',
                        'name' => 'E. Sanare',
                        'described' => 'household head · Spring Basin',
                    ],
                ],
            ],
            [
                'reference' => 'INC-0313',
                'day' => 20,
                'hour' => 5,
                'minute' => 41,
                'subcategory' => 'livestock-depredation',
                'status' => 'in_progress',
                'severity' => 'high',
                'zone' => 'North Gate',
                'title' => 'Lion killed four goats at Riverside — compensation claim opened',
                'narrative' => 'They came in the night. The thorn fence was pushed in on the west side. Four goats dead, one dragged out and eaten. We heard nothing until the dogs. This is the third time since the rains.',
                'source' => 'direct',
                'money' => [
                    'claimed' => 1_600_000,
                    'assessed' => 1_200_000,
                    'approved' => 1_200_000,
                    'settled' => 0,
                ],
                'evidence' => 4,
                'parties' => [
                    [
                        'role' => 'claimant',
                        'name' => 'N. Olesikari',
                        'described' => 'household head · Riverside sub-village',
                    ],
                    [
                        'role' => 'witness',
                        'name' => 'M. Kisioki',
                        'described' => 'herder, present at the boma · statement recorded',
                    ],
                    [
                        'role' => 'animal',
                        'name' => 'Lion · single adult, unmarked',
                        'described' => 'no collar; not matched to a known individual',
                    ],
                ],
            ],
            [
                'reference' => 'INC-0314',
                'day' => 21,
                'hour' => 11,
                'minute' => 5,
                'subcategory' => 'unauthorized-construction',
                'status' => 'in_progress',
                'severity' => 'moderate',
                'zone' => 'Highland Ward',
                'title' => 'Three new structures outside the agreed boma footprint',
                'narrative' => null,
                'source' => 'direct',
                'money' => [
                    'claimed' => null,
                    'assessed' => 1_200_000,
                    'approved' => 1_200_000,
                    'settled' => 0,
                ],
                'evidence' => 2,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0315',
                'day' => 21,
                'hour' => 16,
                'minute' => 20,
                'subcategory' => 'roadkill',
                'status' => 'resolved',
                'severity' => 'low',
                'zone' => 'South Gate',
                'title' => 'Zebra roadkill on the C-road, km 12 — carcass removed',
                'narrative' => null,
                'source' => 'radio',
                'money' => null,
                'evidence' => 1,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0316',
                'day' => 22,
                'hour' => 10,
                'minute' => 52,
                'subcategory' => 'natural-mortality',
                'status' => 'verified',
                'severity' => 'low',
                'zone' => 'North Gate',
                'title' => 'Wildebeest carcass, no injury pattern — natural death recorded',
                'narrative' => null,
                'source' => 'patrol_observation',
                'money' => null,
                'evidence' => 1,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0317',
                'day' => 22,
                'hour' => 8,
                'minute' => 15,
                'subcategory' => 'livestock-depredation',
                'status' => 'reported',
                'severity' => 'moderate',
                'zone' => 'North Gate',
                'title' => 'Fresh lion tracks 400 m from North Gate bomas — households alerted',
                'narrative' => null,
                'source' => 'patrol_observation',
                'money' => null,
                'evidence' => 2,
                'parties' => [],
            ],
            [
                'reference' => 'INC-0318',
                'day' => 22,
                'hour' => 7,
                'minute' => 40,
                'subcategory' => 'snaring',
                'status' => 'verified',
                'severity' => 'critical',
                'zone' => 'Acacia Wood',
                'title' => 'Snare line lifted at the Acacia Wood forest edge — 14 wire snares',
                'narrative' => null,
                'source' => 'patrol_observation',
                'money' => null,
                'evidence' => 3,
                'parties' => [],
            ],
        ];
    }

    /**
     * A point inside the sample area, deterministic per row so two runs of the
     * seeder put the same incident in the same place — a seed whose map moved
     * between runs would be read as data changing.
     *
     * The spread is a coarse lattice over the one area bowl and its
     * surroundings; it is sample geography, not survey data, and nothing in the
     * module treats it as more than a location.
     */
    /**
     * WHEN A ROW WAS REPORTED, RELATIVE TO TODAY — monotonic in index, so the
     * reference order the register reads is still the order things happened.
     *
     * The newest {@see RECENT_COUNT} land between the start of the current month
     * (or three weeks back, whichever is later) and today; the rest fill the
     * weeks before that, back to {@see SPAN_DAYS}. The row's own hour and minute
     * are kept — they are what make a register look like a register rather than a
     * list of midnights.
     */
    public static function reportedAt(int $index, \DateTimeImmutable $today): \DateTimeImmutable
    {
        $rows = self::incidents();
        $row = $rows[$index] ?? throw new \InvalidArgumentException(\sprintf('The sample month has no row %d.', $index));

        $today = $today->setTime(0, 0);
        $recentFrom = max(
            $today->modify('first day of this month'),
            $today->modify(\sprintf('-%d days', self::RECENT_MAX_DAYS)),
        );

        $recentFirst = \count($rows) - self::RECENT_COUNT;
        $day = $index >= $recentFirst
            ? self::spread($recentFrom, $today, $index - $recentFirst, self::RECENT_COUNT)
            : self::spread(
                $today->modify(\sprintf('-%d days', self::SPAN_DAYS)),
                $recentFrom->modify('-1 day'),
                $index,
                $recentFirst,
            );

        return $day->setTime($row['hour'], $row['minute']);
    }

    /**
     * The $position-th of $count points laid evenly across [$from, $to],
     * inclusive of both ends. A single point sits at the start, and a window that
     * has collapsed to nothing — the first of the month, when the run-up has
     * nowhere to go — puts them all on the same day rather than before it.
     */
    private static function spread(\DateTimeImmutable $from, \DateTimeImmutable $to, int $position, int $count): \DateTimeImmutable
    {
        $span = (int) $from->diff($to)->format('%r%a');
        if ($span <= 0 || $count <= 1) {
            return $from;
        }

        return $from->modify(\sprintf('+%d days', intdiv($position * $span, $count - 1)));
    }

    /** How many parties the table names, across every row. */
    public static function partyCount(): int
    {
        return array_sum(array_map(static fn (array $row): int => \count($row['parties']), self::incidents()));
    }

    /** How many pieces of evidence the table names, across every row. */
    public static function evidenceCount(): int
    {
        return array_sum(array_column(self::incidents(), 'evidence'));
    }

    /**
     * THE ANSWERS TO THE QUESTIONS THIS WORD'S BLOCKS ASK, in the shape the blocks
     * keep them — the case file's "what this word asked" section, filled in.
     *
     * Only the blocks the sub-category actually switched on are answered, and every
     * DEFINING answer is given: a seeded record that the report form would have
     * refused is a seed that teaches the wrong rule. A "%d" is replaced by a small
     * number that varies per row, so forty-seven records do not read as one record
     * copied forty-seven times.
     */
    public static function blockAnswersFor(TaxonomySubcategory $subcategory, int $index): BlockAnswers
    {
        $answers = self::ANSWERS[$subcategory->getCode()] ?? [];
        $values = [];
        $claimed = null;

        foreach ($subcategory->getBlocks() as $block) {
            $given = $answers[$block->value] ?? null;
            if (!\is_array($given)) {
                continue;
            }

            if (BehaviorBlockEnum::Money === $block) {
                $figure = $given['claimed'] ?? null;
                $claimed = \is_int($figure) ? $figure : null;

                continue;
            }

            $values[$block->value] = self::varied($given, $index);
        }

        return new BlockAnswers($values, $claimed);
    }

    /**
     * The same answers with the row number worked in, wherever a "%d" says to.
     *
     * @param array<string, mixed> $given
     *
     * @return array<string, mixed>
     */
    private static function varied(array $given, int $index): array
    {
        $n = (string) (1 + $index % 5);
        $out = [];
        foreach ($given as $key => $value) {
            if (\is_string($value)) {
                $out[$key] = str_replace('%d', $n, $value);

                continue;
            }

            if ('rows' === $key && \is_array($value)) {
                $rows = [];
                foreach ($value as $row) {
                    $cells = [];
                    foreach ((array) $row as $cell => $answer) {
                        $cells[$cell] = \is_string($answer) ? str_replace('%d', $n, $answer) : $answer;
                    }
                    $rows[] = $cells;
                }
                $out['rows'] = $rows;
            }
        }

        return $out;
    }

    /**
     * Sample answers per sub-category, keyed by the block that asks them and then
     * by the question's own key. The money block's entry is the figure asked at
     * FILING — the claimant's own, or the officer's at the roadside — and not the
     * money record, which the walk through the workflow records where the money
     * flow allows it.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private const array ANSWERS = [
        'snaring' => [
            'species' => ['species' => 'Impala', 'sex' => 'unknown', 'age_class' => 'adult'],
            'counts' => ['rows' => [['quantity' => 'snares lifted', 'how_many' => '1%d']]],
            'method' => ['method' => 'wire snare', 'gear' => 'Cable, anchored to a stump'],
            'seizures' => ['rows' => [['item' => 'Wire snares', 'how_many' => '1%d', 'description' => 'Hand-made, cable']]],
            'money' => ['claimed' => 250_000],
        ],
        'bushmeat' => [
            'species' => ['species' => 'Blue wildebeest', 'sex' => 'unknown', 'age_class' => 'adult'],
            'counts' => ['rows' => [['quantity' => 'animals', 'how_many' => '%d']]],
            'method' => ['method' => 'wire snare', 'gear' => 'Carried on foot'],
            'parties' => ['rows' => [['role' => 'suspect', 'name' => 'Two men, detained']]],
            'seizures' => ['rows' => [['item' => 'Dried meat', 'how_many' => '%d', 'description' => 'In sacks']]],
            'money' => ['claimed' => 400_000],
        ],
        'ivory-trophy' => [
            'species' => ['species' => 'Elephant', 'sex' => 'male', 'age_class' => 'adult'],
            'method' => ['method' => 'firearm', 'vehicle' => 'A pick-up, not traced'],
            'parties' => ['rows' => [['role' => 'suspect', 'name' => 'One man, detained']]],
            'seizures' => ['rows' => [['item' => 'Worked pieces', 'how_many' => '%d']]],
            'money' => ['claimed' => 1_500_000],
        ],
        'illegal-fishing' => [
            'counts' => ['rows' => [['quantity' => 'animals', 'how_many' => '%d0']]],
            'method' => ['method' => 'net', 'gear' => 'Gill nets, undersized mesh'],
            'parties' => ['rows' => [['role' => 'suspect', 'name' => 'Not identified']]],
            'seizures' => ['rows' => [['item' => 'Gill nets', 'how_many' => '%d']]],
            'named-place' => ['place_kind' => 'water body', 'place_name' => 'A seasonal pool'],
            'money' => ['claimed' => 180_000],
        ],
        'livestock-depredation' => [
            'species' => ['species' => 'Lion', 'sex' => 'unknown', 'age_class' => 'adult'],
            'counts' => ['rows' => [['quantity' => 'head of stock', 'how_many' => '%d']]],
            'parties' => ['rows' => [['role' => 'claimant', 'name' => 'A stock owner', 'household' => 'A boma on the eastern line']]],
            'money' => ['claimed' => 900_000],
        ],
        'crop-raiding' => [
            'money' => ['claimed' => 300_000],
        ],
        'human-injury' => [
            'species' => ['species' => 'Spotted hyena'],
            'casualty' => ['rows' => [['injuries' => 'serious', 'people' => '%d', 'treatment' => 'dispensary', 'facility' => 'A rural dispensary']]],
            'named-place' => ['place_kind' => 'household or boma', 'place_name' => 'A boma on the eastern line'],
            'money' => ['claimed' => 600_000],
        ],
        'property-damage' => [
            'species' => ['species' => 'Elephant'],
            'extent' => ['land_use' => 'cultivation', 'rows' => [['measure' => 'footprint · m²', 'value' => '%d0']]],
            'named-place' => ['place_kind' => 'household or boma', 'place_name' => 'A boma on the eastern line'],
            'money' => ['claimed' => 450_000],
        ],
        'unauthorized-construction' => [
            'counts' => ['rows' => [['quantity' => 'structures', 'how_many' => '%d']]],
            'extent' => ['land_use' => 'settlement', 'rows' => [['measure' => 'footprint · m²', 'value' => '%d00']]],
            'parties' => ['rows' => [['role' => 'suspect', 'name' => 'Named on the notice']]],
            'notice' => ['permit_status' => 'none', 'licence_status' => 'not applicable', 'notice_served' => 'yes'],
            'money' => ['claimed' => 800_000],
        ],
        'illegal-grazing' => [
            'counts' => ['rows' => [['quantity' => 'head of stock', 'how_many' => '%d0']]],
            'extent' => ['land_use' => 'grazing', 'rows' => [['measure' => 'how long it went on · days', 'value' => '2']]],
            'parties' => ['rows' => [['role' => 'suspect', 'name' => 'Named on the notice']]],
            'notice' => ['permit_status' => 'none', 'licence_status' => 'not applicable', 'notice_served' => 'yes'],
            'money' => ['claimed' => 350_000],
        ],
        'boundary-encroachment' => [
            'extent' => ['land_use' => 'cultivation', 'rows' => [['measure' => 'area affected · ha', 'value' => '%d']]],
            'named-place' => ['place_kind' => 'boundary marker', 'place_name' => 'A beacon, intact'],
            'parties' => ['rows' => [['role' => 'suspect', 'name' => 'Named on the notice']]],
            'notice' => ['permit_status' => 'none', 'licence_status' => 'not applicable', 'notice_served' => 'yes'],
            'money' => ['claimed' => 700_000],
        ],
        'unlicensed-operation' => [
            'counts' => ['rows' => [['quantity' => 'vehicles', 'how_many' => '%d']]],
            'method' => ['method' => 'other — not on the list', 'activity' => 'transport', 'operator' => 'Named on the notice'],
            'parties' => ['rows' => [['role' => 'suspect', 'name' => 'Named on the notice']]],
            'notice' => ['permit_status' => 'not applicable', 'licence_status' => 'none', 'notice_served' => 'yes'],
            'money' => ['claimed' => 500_000],
        ],
        'roadkill' => [
            'species' => ['species' => 'Plains zebra', 'sex' => 'female', 'age_class' => 'adult'],
            'condition' => ['condition' => 'dead, fresh', 'carcass_disposition' => 'buried'],
            'named-place' => ['place_kind' => 'road segment', 'place_name' => 'A district road, km 1%d'],
            'money' => ['claimed' => 200_000],
        ],
        'natural-mortality' => [
            'species' => ['species' => 'Blue wildebeest', 'sex' => 'male', 'age_class' => 'adult'],
            'condition' => ['condition' => 'dead, decomposed', 'carcass_disposition' => 'left in situ'],
        ],
        'disease-die-off' => [
            'species' => ['species' => 'Blue wildebeest'],
            'counts' => ['rows' => [['quantity' => 'individuals affected', 'how_many' => '%d']]],
            'samples' => ['rows' => [['samples_taken' => 'blood', 'reference' => 'S-%d', 'sent_to' => 'A veterinary laboratory']]],
            'condition' => ['condition' => 'dead, fresh', 'carcass_disposition' => 'burned'],
        ],
        'poisoning' => [
            'species' => ['species' => 'White-backed vulture'],
            'counts' => ['rows' => [['quantity' => 'individuals affected', 'how_many' => '%d']]],
            'method' => ['method' => 'poison', 'gear' => 'A treated carcass', 'suspected_agent' => 'person'],
            'samples' => ['rows' => [['samples_taken' => 'tissue', 'reference' => 'S-%d', 'sent_to' => 'A veterinary laboratory']]],
            'condition' => ['condition' => 'dead, fresh', 'carcass_disposition' => 'removed to store'],
        ],
    ];
}
