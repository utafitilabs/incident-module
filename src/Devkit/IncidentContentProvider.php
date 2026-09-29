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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\File;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Enum\IncidentSeverityEnum;
use Uhifadhi\Incident\Enum\IncidentSourceEnum;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Enum\PartyRoleEnum;
use Uhifadhi\Incident\Exception\IncidentEvidenceException;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Repository\TaxonomyKindRepository;
use Uhifadhi\Incident\Repository\TaxonomySubcategoryRepository;
use Uhifadhi\Incident\Service\IncidentCaseService;
use Uhifadhi\Incident\Service\IncidentEvidenceService;
use Uhifadhi\Incident\Service\IncidentMoneyService;
use Uhifadhi\Incident\Service\IncidentReportService;
use Uhifadhi\Incident\Service\TaxonomyAdminService;

/**
 * A MONTH OF INCIDENTS TO LOOK AT — the sample month {@see SeedMonth} describes,
 * filed into the area the installation already has, so a developer's first
 * dashboard is a populated one.
 *
 * IT GOES THROUGH THE SAME SERVICES THE SCREENS DO, and that is the whole
 * discipline of it. Every incident is filed by {@see IncidentReportService},
 * every state reached one legal transition at a time through
 * {@see IncidentCaseService}, every money figure recorded by
 * {@see IncidentMoneyService}, every party, assignment and photograph written
 * through the doors a person uses. Seed content written straight to the tables is
 * seed content that can be shaped in ways the product cannot produce, and every
 * such row is a bug report about a screen that is working correctly.
 *
 * THE SAMPLE MONTH ENDS TODAY. It is a shape, not a date — see
 * {@see SeedMonth::reportedAt()} for why a fixed month seeded a dashboard that
 * opened on nothing.
 *
 * AND IT IS PLACED WHERE THE INSTALLATION IS. The sample month says WHAT
 * happened and WHEN; it does not say where, and it must not. Fixed fixture
 * coordinates put every one of the forty-seven a continent away from the only
 * boundary the installation had — the dashboard map fitted itself to a blob in
 * the open ocean and `ST_Within` answered none of forty-seven — and no test
 * caught it, because the suite's own area fixture had been shifted by the same
 * sixty-five degrees. So PostGIS scatters the points inside the area's own
 * geometry ({@see samplePositions()}), deterministically, and an area with no
 * boundary yet is seeded nothing rather than given an invented place.
 *
 * WHAT IS STILL NOT SEEDED, SAID PLAINLY:
 *
 *   MONEY ON A BARE REPORT. Six rows of the sample month carry money at
 *   `reported`, which is a state no screen can produce in either direction — a
 *   claim is taken from `verified` and a fine from `in progress` — so their
 *   figures are left out rather than written past the rule.
 *
 *   DOCUMENTS. The evidence a money case carries is a signed form, and the
 *   platform's default accepted types are images. Photographs are seeded with
 *   real bytes; the signed form waits on a deployment that accepts one.
 *
 * THE REFERENCE IS THE REGISTER'S, not the month's. Filing mints the next one,
 * as it does for a person at the form, so the seeded references run with
 * whatever the installation already has rather than colliding with it.
 *
 * IT IS COLLECTED, NOT RUN. devkit installs through `require-dev`; in a
 * production build nothing collects this and it is an ordinary service nobody
 * ever asks anything of.
 *
 * THE ENTITY MANAGER IS HERE TO READ, never to write a record. Which area exists
 * and who has an account are questions only the installation can answer, and
 * every service below writes and flushes for itself. Nothing here constructs or
 * persists a row.
 *
 * @see ContentProviderInterface
 */
final readonly class IncidentContentProvider implements ContentProviderInterface
{
    /** Kept clear of the boundary, as a fraction of the area's narrow side. */
    private const float INTERIOR_MARGIN = 0.05;

    /**
     * THE INTERIOR THE SEED IS ALLOWED TO USE: the area's boundary eroded by
     * {@see INTERIOR_MARGIN} of its narrow side, so a seeded incident is not
     * sitting on the line where it is impossible to tell which side of the
     * boundary it is on. An area too narrow to erode keeps its own outline.
     *
     * ST_MakeValid first, because an imported boundary is whatever the shapefile
     * had in it, and a self-intersecting ring makes every predicate after it
     * answer nonsense rather than fail.
     */
    private const string INTERIOR_CTE = <<<'SQL'
        WITH raw AS (
            SELECT ST_MakeValid(geom) AS geom FROM area_of_interest WHERE id = :id AND geom IS NOT NULL
        ), sized AS (
            SELECT geom, LEAST(ST_XMax(geom) - ST_XMin(geom), ST_YMax(geom) - ST_YMin(geom)) AS narrow FROM raw
        ), interior AS (
            SELECT CASE
                       WHEN ST_IsEmpty(ST_Buffer(geom, -narrow * %1$F)) THEN geom
                       ELSE ST_Buffer(geom, -narrow * %1$F)
                   END AS geom
            FROM sized
        )
        SQL;

    /**
     * THE PHOTOGRAPH EVERY SEEDED PIECE OF EVIDENCE IS. A small flat rectangle,
     * base64 of a real PNG — real enough that the platform detects its type,
     * measures it and makes a preview, which is the whole point of seeding
     * through the storage path rather than writing keys.
     *
     * Deliberately not a picture of anything. Seed evidence stands for the SHAPE
     * of a record; a stock photograph of a snare or a carcass would be a claim
     * about a place, and this seeder files into whatever area an installation has.
     */
    private const string PHOTOGRAPH = 'iVBORw0KGgoAAAANSUhEUgAAAGAAAABICAIAAACGBWc0AAAAcklEQVR42u3QMQ0AAAgDsPkXxUmQhQNujiZV0EwXhygQJEiQIEGC'
        .'BAlCkCBBggQJEiQIQYIECRIkSJAgBAkSJEiQIEGCBCFIkCBBggQJEoQgQYIECRIkSBCCBAkSJEiQIEGCECRIkCBBggQJQpAgQYK+WKVJB6MfJAAJAAAAAElFTkSuQmCC';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaxonomyAdminService $taxonomy,
        private TaxonomyKindRepository $kinds,
        private TaxonomySubcategoryRepository $subcategories,
        private IncidentRepository $incidents,
        private IncidentReportService $reports,
        private IncidentMoneyService $money,
        private IncidentCaseService $cases,
        private IncidentEvidenceService $evidence,
    ) {
    }

    public function key(): string
    {
        return 'incident';
    }

    public function label(): string
    {
        return 'Incidents';
    }

    public function description(): string
    {
        return 'A month of incidents filed in the first area — poaching, conflict, compliance and mortality, walked to the states the register shows, with their parties, responders and photographs.';
    }

    /**
     * The people. Incidents are recorded BY somebody, and a register whose every
     * row was recorded by nobody says nothing about who is doing the work.
     *
     * Areas are not named here, deliberately: nothing installed ships area seed
     * content yet, and devkit refuses an edge to a key no provider declares. The
     * area is taken from whatever the installation has.
     *
     * @return list<string>
     */
    public function dependsOn(): array
    {
        return ['team'];
    }

    public function load(): void
    {
        $area = $this->firstArea();
        if (null === $area) {
            // An installation with no area is one with nowhere to file, and that
            // is a state rather than a failure.
            return;
        }

        // THE AREA'S OWN WORDS FIRST, through the kinds editor's own service, so
        // everything this seeder files appears in that editor. There is nothing to
        // file an incident against until they exist, and a seeder that failed for
        // that reason would send somebody hunting for a bug that is really a
        // missing vocabulary.
        $this->seedTheVocabulary($area);

        if ($this->incidents->countFor($area) > 0) {
            // SEEDED ONCE. A second run would file forty-seven more incidents and
            // every total the gallery states would stop being true.
            return;
        }

        $rows = SeedMonth::incidents();
        $positions = $this->samplePositions($area, \count($rows));
        if ([] === $positions) {
            // An area that is gazetted and named but whose boundary has not been
            // imported yet. There is no honest place to put an incident in it,
            // and inventing one is the bug this guard exists for.
            return;
        }

        $today = new \DateTimeImmutable();
        $recorders = $this->recorders();

        foreach ($rows as $index => $row) {
            $subcategory = $this->subcategories->findOneByAreaAndCode($area, $row['subcategory']);
            if (null === $subcategory) {
                continue;
            }

            $reportedAt = SeedMonth::reportedAt($index, $today);
            $recorder = [] === $recorders ? null : $recorders[$index % \count($recorders)];

            $incident = $this->reports->file(
                area: $area,
                subcategory: $subcategory,
                title: $row['title'],
                position: $positions[$index],
                now: $reportedAt,
                severity: IncidentSeverityEnum::from($row['severity']),
                source: IncidentSourceEnum::from($row['source']),
                occurredAt: $reportedAt->modify('-2 hours'),
                narrative: $row['narrative'],
                reportedBy: $recorder,
                blockAnswers: SeedMonth::blockAnswersFor($subcategory, $index),
            );

            $this->addParties($incident, $row['parties'], $reportedAt, $recorder);
            $this->attachEvidence($incident, $row['evidence'], $reportedAt, $recorder);
            $this->walkTo(
                $incident,
                IncidentStatusEnum::from($row['status']),
                $reportedAt,
                $recorder,
                // Whoever carries the response, once there is one to carry. A
                // different person from the one who filed it wherever the
                // installation has more than one account, because a register in
                // which everybody reports to themselves says nothing about a team.
                [] === $recorders ? null : $recorders[($index + 1) % \count($recorders)],
                $row['money'],
            );
        }
    }

    /**
     * THE SAMPLE MONTH'S KINDS, WRITTEN INTO THE AREA — through
     * {@see TaxonomyAdminService}, the same door the kinds editor uses, so every
     * word this seeder files against is a word an administrator can see, rename
     * and retire.
     *
     * SEEDED ONCE. An area that already has a kind is left exactly as it is: its
     * words are its own, and a seeder is not entitled to add to them.
     */
    private function seedTheVocabulary(AreaOfInterest $area): void
    {
        if ($this->kinds->areaHasAny($area)) {
            return;
        }

        foreach (SeedMonth::kinds() as $code => $definition) {
            $kind = $this->taxonomy->createKind($area, $definition['label'], $code);
            $this->taxonomy->setKindLeads($kind, $definition['leads']);

            foreach ($definition['subcategories'] as $subCode => $sub) {
                $subcategory = $this->taxonomy->createSubcategory($kind, $sub['label'], $subCode);

                // THE BLOCKS THIS WORD SWITCHES ON — which is the whole of what
                // its form asks. The money block only where the design says money
                // runs, and a word with no direction gets none, which is what
                // makes the money question ABSENT from its form rather than empty
                // on it.
                $direction = null === $sub['money'] ? null : MoneyDirectionEnum::from($sub['money']);
                $this->taxonomy->setBlocks($subcategory, self::blocksOf($sub['blocks']), $direction);
                $this->taxonomy->setTermHours($subcategory, $sub['term_hours']);
            }
        }
    }

    /**
     * The blocks the table names, as the enum — a value the enum does not know is
     * dropped rather tha seeded, because a seed may not teach a block that does
     * not exist.
     *
     * @param list<string> $blocks
     *
     * @return list<BehaviorBlockEnum>
     */
    private static function blocksOf(array $blocks): array
    {
        $cases = [];
        foreach ($blocks as $value) {
            $block = BehaviorBlockEnum::tryFrom($value);
            if (null !== $block) {
                $cases[] = $block;
            }
        }

        return $cases;
    }

    /**
     * Move a freshly filed incident to where the sample month says it is — one
     * legal transition at a time, with the assignee named and the money recorded
     * at the point the product allows, which is the DIRECTION's point: a
     * compensation claim as soon as the case is verified, a fine once response has
     * started. Either way it lands before the resolve that reads it.
     *
     * @param array{claimed: int|null, assessed: int|null, approved: int|null, settled: int|null}|null $money
     */
    private function walkTo(
        Incident $incident,
        IncidentStatusEnum $target,
        \DateTimeImmutable $reportedAt,
        ?UserInterface $actor,
        ?UserInterface $responder,
        ?array $money,
    ): void {
        $at = $reportedAt;

        foreach ([
            IncidentTransitionEnum::Verify,
            IncidentTransitionEnum::Respond,
            IncidentTransitionEnum::Resolve,
        ] as $step) {
            if (!$target->hasReached($step->toPlace())) {
                return;
            }

            $at = $at->modify('+7 hours');
            $this->cases->move($incident, $step, $at, $actor, self::nameOf($actor));

            if (IncidentTransitionEnum::Respond === $step) {
                $this->cases->assign($incident, $responder, $at, $actor, self::nameOf($actor));
            }

            $direction = $incident->getSubcategory()->getMoneyDirection();
            if (null !== $money
                && null !== $direction
                && null === $incident->getMoney()
                && $incident->getStatus()->hasReached($direction->recordableFrom())
            ) {
                $this->money->record(
                    $incident,
                    $money['claimed'],
                    $money['assessed'],
                    $money['approved'],
                    $money['settled'] ?? 0,
                    $at,
                    $actor,
                    self::nameOf($actor),
                );
            }
        }

        if (IncidentStatusEnum::Closed === $target) {
            // The clock's own move, and it is made by the clock: the term after
            // resolution, with no actor. Even here.
            $this->cases->closeIfDue($incident, $at->modify('+31 days'));
        }
    }

    /**
     * The people and the animals the sample month names on a case file, added the
     * way a person adds them — role, name and the line under it.
     *
     * @param list<array{role: string, name: string, described: string|null}> $parties
     */
    private function addParties(Incident $incident, array $parties, \DateTimeImmutable $at, ?UserInterface $actor): void
    {
        foreach ($parties as $party) {
            $role = PartyRoleEnum::tryFrom($party['role']);
            if (null === $role) {
                continue;
            }

            $this->cases->addParty(
                $incident,
                $role,
                $party['name'],
                $party['described'],
                at: $at->modify('+29 minutes'),
                actor: $actor,
                actorName: self::nameOf($actor),
            );
        }
    }

    /**
     * The photographs, with real bytes behind them — written through the same
     * evidence door a browser upload takes, so the Files hub reads a genuine size
     * and a genuine preview back rather than a key naming nothing.
     *
     * A file the deployment does not accept is skipped rather than fatal: a
     * deployment that narrowed its accepted types has made a decision, and a
     * seeder is not the place to argue with it.
     */
    private function attachEvidence(Incident $incident, int $count, \DateTimeImmutable $at, ?UserInterface $actor): void
    {
        for ($n = 1; $n <= $count; ++$n) {
            $capturedAt = $at->modify(\sprintf('+%d minutes', 90 + $n));

            try {
                $this->evidence->store(
                    $incident,
                    $this->aPhotograph(),
                    \sprintf('IMG_%04d.png', ($incident->getId() ?? 0) * 10 + $n),
                    capturedAt: $capturedAt,
                    position: $incident->getPosition(),
                    at: $capturedAt,
                    actor: $actor,
                    actorName: self::nameOf($actor),
                );
            } catch (IncidentEvidenceException) {
                return;
            }
        }
    }

    /**
     * ONE POINT PER INCIDENT, INSIDE THE AREA — asked of PostGIS, because the
     * boundary is a geometry and only the database can answer where its inside
     * is.
     *
     * ST_GeneratePoints in its three-argument form takes a SEED, so this is
     * deterministic: the same area seeded twice puts the same incident in the
     * same place, and a screenshot of the seed keeps meaning something. Ordered
     * by latitude then longitude for the same reason — the mapping from row to
     * place must not depend on what order the database felt like returning.
     *
     * @return list<string> GeoJSON Point text, one per row, or an empty list where
     *                      the area has no boundary to place anything in
     */
    private function samplePositions(AreaOfInterest $area, int $count): array
    {
        if (!$area->hasBoundary()) {
            return [];
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            \sprintf(self::INTERIOR_CTE, self::INTERIOR_MARGIN).
            ' SELECT ST_X(p) AS lon, ST_Y(p) AS lat
              FROM (SELECT (ST_Dump(ST_GeneratePoints(interior.geom, :count, :seed))).geom AS p FROM interior) d
              ORDER BY ST_Y(p), ST_X(p)',
            ['id' => $area->getId(), 'count' => $count, 'seed' => SeedMonth::RANDOM_SEED],
        );

        $positions = [];
        foreach ($rows as $row) {
            if (is_numeric($row['lon'] ?? null) && is_numeric($row['lat'] ?? null)) {
                $positions[] = \sprintf('{"type":"Point","coordinates":[%.6F,%.6F]}', (float) $row['lon'], (float) $row['lat']);
            }
        }

        // Fewer points than rows is a shape ST_GeneratePoints could not fill —
        // seeding a partial month would be a seed that silently disagrees with
        // every total the gallery states, so it seeds none.
        return \count($positions) >= $count ? \array_slice($positions, 0, $count) : [];
    }

    /** The seeded photograph, on disk where the platform's storage can read it. */
    private function aPhotograph(): File
    {
        $path = tempnam(sys_get_temp_dir(), 'incident-seed-evidence');
        if (false === $path) {
            throw new \RuntimeException('The seed photograph could not be written to a temporary file.');
        }

        file_put_contents($path, base64_decode(self::PHOTOGRAPH, true) ?: '');

        return new File($path);
    }

    /**
     * The area to file into — the first the installation has. Read through the
     * platform's area contract rather than a bundle's class, because which class
     * answers it is the installation's business.
     */
    private function firstArea(): ?AreaOfInterest
    {
        $areas = $this->entityManager->getRepository(AreaOfInterest::class)->findBy([], ['id' => 'ASC'], 1);

        return $areas[0] ?? null;
    }

    /**
     * Whoever the installation already has accounts for, oldest first. This
     * creates no people: accounts belong to whoever owns them, and inventing some
     * here would put names on a performance page that nobody recognises.
     *
     * @return list<UserInterface>
     */
    private function recorders(): array
    {
        /** @var list<UserInterface> $users */
        $users = $this->entityManager->getRepository(UserInterface::class)->findBy([], ['id' => 'ASC'], 6);

        return $users;
    }

    private static function nameOf(?UserInterface $user): ?string
    {
        if (null === $user) {
            return null;
        }

        $first = (string) $user->getFirstName();
        $last = (string) $user->getLastName();
        $name = trim(('' !== $first ? mb_substr($first, 0, 1).'. ' : '').$last);

        return '' !== $name ? $name : null;
    }
}
