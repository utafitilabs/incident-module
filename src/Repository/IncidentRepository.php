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

namespace Uhifadhi\Incident\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentMoney;
use Uhifadhi\Incident\Entity\TaxonomySubcategory;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Model\IncidentFilter;
use Uhifadhi\Incident\Workflow\IncidentWorkflow;

/**
 * EVERY QUESTION THE INCIDENTS SURFACES ASK, in one place.
 *
 * ONE FILTER DRIVES EVERYTHING. The design is explicit that the map, the
 * register and the charts read the SAME query — so every counting method below
 * takes the same {@see IncidentFilter} the list does, and applies it the same
 * way ({@see applyFilter()}). A chart that quietly ignored the category chips
 * would make the dashboard lie about which numbers belong to which rows.
 *
 * NOTHING HERE FILTERS BY DEPARTMENT. {@see findByScopeBetween()} reads an
 * area's incidents, or every area's, and never asks who recorded them.
 *
 * @extends ServiceEntityRepository<Incident>
 */
final class IncidentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Incident::class);
    }

    public function findOneByUuid(Uuid $uuid): ?Incident
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function findOneByReference(string $reference): ?Incident
    {
        return $this->findOneBy(['reference' => $reference]);
    }

    /**
     * EVERY RESOLVED INCIDENT THE CLOCK IS NOW DUE TO CLOSE — deployment-wide,
     * across every area, because the clock keeps nobody's hours and an area
     * boundary is not a thing time respects.
     *
     * Due means resolved at least {@see IncidentWorkflow::CLOSE_AFTER_DAYS} ago:
     * the same threshold {@see IncidentTransitionService::closesAt()} computes, said
     * here in SQL so the sweep loads only the rows it will actually close rather
     * than every resolved incident an installation has ever had. The service is
     * still asked incident-by-incident afterwards — this query narrows the set, it
     * does not replace the guard.
     *
     * @return list<Incident>
     */
    public function dueForClosure(\DateTimeImmutable $now): array
    {
        $threshold = $now->modify(\sprintf('-%d days', IncidentWorkflow::CLOSE_AFTER_DAYS));

        /** @var list<Incident> $incidents */
        $incidents = $this->createQueryBuilder('i')
            ->andWhere('i.status = :resolved')->setParameter('resolved', IncidentStatusEnum::Resolved->value)
            ->andWhere('i.resolvedAt IS NOT NULL')
            ->andWhere('i.resolvedAt <= :threshold')->setParameter('threshold', $threshold)
            ->orderBy('i.resolvedAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $incidents;
    }

    /**
     * THE NEXT NUMBER PEOPLE WILL SAY OUT LOUD. Deployment-wide rather than
     * per-area, because a reference is quoted on radio and on paper where nobody
     * repeats which area they meant.
     *
     * Derived from the highest reference rather than from a count: incidents are
     * never deleted in practice, but a count would silently start reusing numbers
     * if one ever were, and two case files with the same number is the one
     * failure this record type cannot survive.
     */
    public function nextReference(string $prefix = 'INC-'): string
    {
        /** @var string|null $highest */
        $highest = $this->createQueryBuilder('i')
            ->select('MAX(i.reference)')
            ->andWhere('i.reference LIKE :prefix')
            ->setParameter('prefix', $prefix.'%')
            ->getQuery()
            ->getSingleScalarResult();

        $next = null === $highest ? 1 : ((int) substr($highest, \strlen($prefix))) + 1;

        return \sprintf('%s%04d', $prefix, $next);
    }

    /**
     * The register, the feed and the map all read this — one query, one filter,
     * newest first.
     *
     * @return list<Incident>
     */
    public function findFiltered(IncidentFilter $filter, ?int $limit = null): array
    {
        $qb = $this->loaded($filter)
            ->orderBy('i.reportedAt', 'DESC')
            ->addOrderBy('i.id', 'DESC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        /** @var list<Incident> $incidents */
        $incidents = $qb->getQuery()->getResult();

        return $incidents;
    }

    /**
     * FILINGS PER CALENDAR MONTH across the last {@see $months} months up to and
     * including the month {@see $now} falls in — the trend line's series, oldest
     * month first, every month in the span present at zero.
     *
     * THE ONE FIGURE WHOSE WINDOW IS NOT THE PAGE'S. Every other reading on the
     * dashboard is of the one month the surface loaded; a line needs more than one
     * month, so this aggregate carries its own six and overrides the filter's
     * window with them. Everything else about the filter still applies — the
     * category chips, the lens, the zone and the search narrow the trend exactly
     * as they narrow the register, because one filter drives everything.
     *
     * The set is one area's filings over six months, the same order of magnitude
     * as the register the surface already draws, so it is loaded and bucketed in
     * PHP rather than grouped in SQL — the month keys a portable query cannot
     * form are formed here, once, from the timestamps.
     *
     * @return array<string, int> 'Y-m' => count, every month in the span present
     */
    public function monthlyFiledCounts(IncidentFilter $filter, \DateTimeImmutable $now, int $months = 6): array
    {
        $end = $now->modify('first day of next month')->setTime(0, 0);
        $start = $end->modify(\sprintf('-%d months', $months));

        $qb = $this->createQueryBuilder('i')
            ->join('i.subcategory', 's')
            ->join('s.kind', 'k')
            ->select('i.reportedAt AS reportedAt');
        $this->applyFilter($qb, $filter->inWindow($start, $end));

        /** @var list<array{reportedAt: \DateTimeImmutable}> $rows */
        $rows = $qb->getQuery()->getResult();

        $counts = [];
        for ($month = $start; $month < $end; $month = $month->modify('+1 month')) {
            $counts[$month->format('Y-m')] = 0;
        }
        foreach ($rows as $row) {
            $key = $row['reportedAt']->format('Y-m');
            if (isset($counts[$key])) {
                ++$counts[$key];
            }
        }

        return $counts;
    }

    /**
     * THE REGISTER BEHIND THE REPORT DRAWER — this area's newest filings, capped.
     *
     * Deliberately NOT {@see findFiltered()}: the drawer's backdrop answers "what
     * am I filing next to", and a month window would empty it on the first of the
     * month, leaving a drawer over nothing. Unfiltered and unpaged, because it is
     * context rather than a listing nobody can act on through a backdrop.
     *
     * @return list<Incident>
     */
    public function recentForArea(AreaOfInterest $area, int $limit): array
    {
        /** @var list<Incident> $incidents */
        $incidents = $this->createQueryBuilder('i')
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.kind', 'k')->addSelect('k')
            // The zone comes with it: every row that prints one prints it, and a
            // lazy association here is one query per row on a list of rows.
            ->leftJoin('i.zone', 'z')->addSelect('z')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->orderBy('i.reportedAt', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $incidents;
    }

    /*
     * ── WHAT THE AREA OVERVIEW ASKS ─────────────────────────────────────────
     *
     * The module's own dashboard loads ONE month and reads it nine ways, because
     * every widget on that screen is a reading of the same window. The overview
     * is the opposite shape: four small cards asking four unrelated questions
     * about four different sets — where the open work is (all of it), what came
     * in today, the newest handful, and what is still owed (which has no window
     * at all). Loading a month would answer none of them, and loading the whole
     * register to count it in PHP would be a table scan for a bar chart.
     *
     * So: aggregates in SQL where the set is unbounded, and rows in PHP where
     * the set is bounded by the question itself (open work, today's filings, an
     * area's outstanding money).
     */

    /** How many incidents this area has ever had — the denominator on "6 of 47". */
    public function countFor(AreaOfInterest $area): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * THE FIVE-STATE BAR: how many incidents are sitting in each place right now.
     *
     * In SQL rather than in PHP because it is the ONE overview figure whose set
     * is the whole register: an area with ten years of filings would otherwise
     * load ten years to draw five numbers.
     *
     * @return array<string, int> status value => count, every place present
     */
    public function statusTallyFor(AreaOfInterest $area): array
    {
        // Doctrine converts an `enumType` column even in a scalar select, so the
        // key comes back as the enum and not as the string it is stored as.
        /** @var list<array{status: IncidentStatusEnum, n: int|string}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.status AS status, COUNT(i.id) AS n')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->groupBy('i.status')
            ->getQuery()
            ->getResult();

        // Every place present, at zero where nothing is there — a bar that
        // dropped an empty segment would redraw itself on a quiet week and the
        // reader would think the workflow had changed.
        $tally = [];
        foreach (IncidentStatusEnum::ordered() as $place) {
            $tally[$place->value] = 0;
        }
        foreach ($rows as $row) {
            $tally[$row['status']->value] = (int) $row['n'];
        }

        return $tally;
    }

    /**
     * EVERY INCIDENT STILL SOMEBODY'S WORK, with the taxonomy and the zone
     * loaded — the set behind the past-term list, the attention items and the
     * open-incidents map layer.
     *
     * Rows and not a count, because each of those needs the incident itself; the
     * set is bounded by the work being open, which is the number an area manager
     * could in principle read one morning.
     *
     * @return list<Incident>
     */
    public function openFor(AreaOfInterest $area): array
    {
        /** @var list<Incident> $incidents */
        $incidents = $this->createQueryBuilder('i')
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.kind', 'k')->addSelect('k')
            ->leftJoin('i.zone', 'z')->addSelect('z')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->andWhere('i.status IN (:open)')
            ->setParameter('open', array_map(
                static fn (IncidentStatusEnum $place) => $place->value,
                array_filter(IncidentStatusEnum::ordered(), static fn (IncidentStatusEnum $place) => $place->isOpen()),
            ))
            ->orderBy('i.reportedAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $incidents;
    }

    /**
     * HOW MANY WERE FILED AGAINST EACH SUB-CATEGORY, by its wire-code.
     *
     * Keyed by the CODE rather than by id because the reader of this answer is
     * the area's own vocabulary, whose sub-categories are told apart by a
     * wire-code and not by a row id.
     *
     * @return array<string, int> sub-category wire-code => how many the window holds
     */
    public function countsBySubcategoryCode(AreaOfInterest $area, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->select('s.code AS code, COUNT(i.id) AS n')
            ->join('i.subcategory', 's')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->groupBy('s.code');

        if (null !== $from) {
            $qb->andWhere('i.reportedAt >= :from')->setParameter('from', $from);
        }
        if (null !== $to) {
            $qb->andWhere('i.reportedAt < :to')->setParameter('to', $to);
        }

        /** @var list<array{code: string, n: int|string}> $rows */
        $rows = $qb->getQuery()->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['code']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * What was FILED in a window — one day of it, on the overview.
     *
     * @return list<Incident>
     */
    public function filedBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<Incident> $incidents */
        $incidents = $this->createQueryBuilder('i')
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.kind', 'k')->addSelect('k')
            ->leftJoin('i.zone', 'z')->addSelect('z')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->andWhere('i.reportedAt >= :from')->setParameter('from', $from)
            ->andWhere('i.reportedAt < :to')->setParameter('to', $to)
            ->orderBy('i.reportedAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $incidents;
    }

    /**
     * HOW MANY WERE FINISHED WITH in a window — resolved or closed IN it, whenever
     * they were filed. A different question from {@see filedBetween()} and
     * deliberately not reconcilable with it: a day's work is not a day's filings.
     */
    /**
     * HOW MANY CAME IN INSIDE A WINDOW — counted in SQL, because the caller
     * wants the number and not the rows. `filedBetween()` is the other half of
     * this and hydrates a day's worth; a month's worth, loaded to be counted,
     * is a page that gets slower every year it runs.
     */
    public function countFiledBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->andWhere('i.reportedAt >= :from')->setParameter('from', $from)
            ->andWhere('i.reportedAt < :to')->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countClosedOutBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->closedOut($area, $from, $to)
            ->select('COUNT(i.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The same set as rows — what the map's "resolved &amp; closed" layer draws.
     *
     * THE LAYER IS WINDOWED AND THE LEGEND SAYS SO. Every incident an area ever
     * closed is unbounded and, on the morning this page is for, uninteresting: a
     * plate about today does not need last year's roadkill. The window is the
     * layer's own, stated in its label, rather than a silent cap that would make
     * the map disagree with the register.
     *
     * @return list<Incident>
     */
    public function closedOutBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<Incident> $incidents */
        $incidents = $this->closedOut($area, $from, $to)
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.kind', 'k')->addSelect('k')
            ->leftJoin('i.zone', 'z')->addSelect('z')
            ->orderBy('i.reportedAt', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $incidents;
    }

    private function closedOut(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->andWhere('(i.resolvedAt >= :from AND i.resolvedAt < :to) OR (i.closedAt >= :from AND i.closedAt < :to)')
            ->setParameter('from', $from)
            ->setParameter('to', $to);
    }

    /**
     * THE MONEY THIS AREA IS STILL OWED OR STILL OWES — every incident whose money
     * record has not been settled and has not been waived, oldest first.
     *
     * The outstanding balance is derived in PHP everywhere else
     * ({@see IncidentMoney::outstanding()}), and the
     * SQL here says the same thing in SQL rather than loading every money record
     * an area has ever had to filter four of them out. The COALESCE is that
     * method's own fallback chain — approved, then assessed, then claimed — and if
     * one changes the other must.
     *
     * NO WINDOW. This is the one figure on the overview that is not about today:
     * a claim approved in july is still unpaid in august, and windowing it would
     * quietly forgive it on the first of the month.
     *
     * @return list<Incident>
     */
    public function outstandingMoneyFor(AreaOfInterest $area): array
    {
        /** @var list<Incident> $incidents */
        $incidents = $this->createQueryBuilder('i')
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.kind', 'k')->addSelect('k')
            ->leftJoin('i.zone', 'z')->addSelect('z')
            ->join('i.money', 'm')->addSelect('m')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->andWhere('m.waivedAt IS NULL')
            ->andWhere('COALESCE(m.approved, m.assessed, m.claimed, 0) > m.settled')
            ->orderBy('i.reportedAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $incidents;
    }

    /**
     * The money PUT ON THE RECORD in a window, both directions, as the sums the
     * "assessed this month" line prints.
     *
     * Scoped by when the INCIDENT was filed, because a figure has no timestamp of
     * its own — which is exactly why the line says "assessed this month" against a
     * month of filings and not "assessed in the last thirty days".
     *
     * @return array<string, int> money direction value => the sum signed off
     */
    public function moneyAssessedBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{direction: MoneyDirectionEnum, total: int|string|null}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->join('i.money', 'm')
            ->select('m.direction AS direction, SUM(COALESCE(m.approved, m.assessed, m.claimed, 0)) AS total')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->andWhere('i.reportedAt >= :from')->setParameter('from', $from)
            ->andWhere('i.reportedAt < :to')->setParameter('to', $to)
            ->groupBy('m.direction')
            ->getQuery()
            ->getResult();

        $totals = [];
        foreach (MoneyDirectionEnum::cases() as $direction) {
            $totals[$direction->value] = 0;
        }
        foreach ($rows as $row) {
            $totals[$row['direction']->value] = (int) $row['total'];
        }

        return $totals;
    }

    /**
     * How long each verified incident took to be verified, in hours — the set the
     * MEDIAN is taken over.
     *
     * Two columns rather than the rows, because nothing about this figure needs
     * an incident: it is the one aggregate on the overview whose set really is
     * "everything this area ever verified".
     *
     * @return list<float>
     */
    public function hoursToVerifyFor(AreaOfInterest $area): array
    {
        /** @var list<array{reportedAt: \DateTimeImmutable, verifiedAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.reportedAt AS reportedAt, i.verifiedAt AS verifiedAt')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->andWhere('i.verifiedAt IS NOT NULL')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): float => ($row['verifiedAt']->getTimestamp() - $row['reportedAt']->getTimestamp()) / 3600,
            $rows,
        );
    }

    /**
     * THE TERMS THIS AREA ACTUALLY WORKS TO, shortest promise first.
     *
     * Read from the register rather than from the whole vocabulary: the card
     * names the shortest and the longest term an incident HERE is held to, and a
     * two-hour word nobody in this area has ever filed under would otherwise be
     * quoted as a term it does not keep.
     *
     * @return list<TaxonomySubcategory>
     */
    public function termsInUseFor(AreaOfInterest $area): array
    {
        /** @var list<TaxonomySubcategory> $subcategories */
        $subcategories = $this->getEntityManager()->createQueryBuilder()
            ->select('s')
            ->from(TaxonomySubcategory::class, 's')
            ->join(Incident::class, 'i', Join::WITH, 'i.subcategory = s')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->groupBy('s.id')
            ->orderBy('s.termHours', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $subcategories;
    }

    /**
     * EVERYTHING WAITING ON ONE PERSON, oldest first: an incident they reported
     * that nobody has verified, or one they are the responder on. Closed and
     * resolved work is not waiting on anybody, so it is not here.
     *
     * @return list<Incident>
     */
    public function queueFor(UserInterface $user, ?AreaOfInterest $area = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.kind', 'k')->addSelect('k')
            ->andWhere('i.status IN (:open)')
            ->setParameter('open', ['reported', 'verified', 'in_progress'])
            ->andWhere('i.assignedTo = :user OR (i.reportedBy = :user AND i.status = :reported)')
            ->setParameter('user', $user)
            ->setParameter('reported', 'reported')
            ->orderBy('i.reportedAt', 'ASC')
            ->addOrderBy('i.id', 'ASC');

        if (null !== $area) {
            $qb->andWhere('i.area = :area')->setParameter('area', $area);
        }

        /** @var list<Incident> $incidents */
        $incidents = $qb->getQuery()->getResult();

        return $incidents;
    }

    /**
     * THE ONE THIS PERSON LAST TOUCHED — what the "where things stand" rail is
     * scoped to. "Touched" means appearing as the actor on the most recent event,
     * which is precisely the set of things a person did: a transition, a note, an
     * attachment, a change to the money.
     *
     * Null when they have touched nothing; the rail then falls back to the head of
     * {@see queueFor()} and says so in its own header, rather than rendering an
     * empty card.
     */
    public function lastTouchedBy(UserInterface $user, ?AreaOfInterest $area = null): ?Incident
    {
        $qb = $this->createQueryBuilder('i')
            ->join('i.events', 'e')
            ->andWhere('e.actor = :user')
            ->setParameter('user', $user)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults(1);

        if (null !== $area) {
            $qb->andWhere('i.area = :area')->setParameter('area', $area);
        }

        /** @var Incident|null $incident */
        $incident = $qb->getQuery()->getOneOrNullResult();

        return $incident;
    }

    /**
     * EVERY INCIDENT RECORDED IN A SCOPE in a window, with the taxonomy and the
     * money already loaded — the rows behind every department KPI plate.
     *
     * The scope is one area, named by its uuid, or every area when null. Who
     * recorded an incident, and whether they hold a position anywhere, plays no
     * part.
     *
     * ONE query rather than one per figure, and the arithmetic in PHP: the plates
     * are readings of the same month's rows, and asking once per plate would let
     * them disagree.
     *
     * @return list<Incident>
     */
    public function findByScopeBetween(?string $areaUuid, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $qb = $this->createQueryBuilder('i')
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.kind', 'k')->addSelect('k')
            ->leftJoin('i.money', 'm')->addSelect('m')
            ->andWhere('i.reportedAt >= :from')->setParameter('from', $from)
            ->andWhere('i.reportedAt < :until')->setParameter('until', $until)
            ->orderBy('i.reportedAt', 'ASC');

        if (null !== $areaUuid) {
            $qb->join('i.area', 'sa')
                ->andWhere('sa.uuid = :scope_area')
                ->setParameter('scope_area', Uuid::fromString($areaUuid), 'uuid');
        }

        /** @var list<Incident> $incidents */
        $incidents = $qb->getQuery()->getResult();

        return $incidents;
    }

    /**
     * EVERY INCIDENT FINISHED IN A SCOPE in a window — the rows behind
     * "resolved this period" and behind the closed line of the topic's flow
     * chart.
     *
     * A SECOND QUESTION, NOT A FILTER ON THE FIRST. What was filed in August
     * and what was finished in August are different sets, and a page that
     * derived one from the other could only ever report the overlap.
     *
     * @return list<Incident>
     */
    public function findResolvedByScopeBetween(?string $areaUuid, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $qb = $this->performanceRows()
            ->andWhere('i.resolvedAt >= :from')->setParameter('from', $from)
            ->andWhere('i.resolvedAt < :until')->setParameter('until', $until)
            ->orderBy('i.resolvedAt', 'ASC');

        /** @var list<Incident> $incidents */
        $incidents = self::narrowedTo($qb, $areaUuid)->getQuery()->getResult();

        return $incidents;
    }

    /**
     * EVERY INCIDENT IN A SCOPE THAT IS STILL OPEN, whenever it was filed —
     * the stock behind "how long an open incident has been open".
     *
     * NOT A WINDOW. The question the chart asks is about the backlog as it
     * stands, and a backlog clipped to one month would hide exactly the rows
     * worth looking at.
     *
     * @return list<Incident>
     */
    public function findOpenByScope(?string $areaUuid): array
    {
        $qb = $this->performanceRows()
            ->andWhere('i.status IN (:open)')
            ->setParameter('open', array_map(
                static fn (IncidentStatusEnum $place) => $place->value,
                array_filter(IncidentStatusEnum::ordered(), static fn (IncidentStatusEnum $place) => $place->isOpen()),
            ))
            ->orderBy('i.reportedAt', 'ASC');

        /** @var list<Incident> $incidents */
        $incidents = self::narrowedTo($qb, $areaUuid)->getQuery()->getResult();

        return $incidents;
    }

    /**
     * HOW MANY INCIDENTS A SCOPE HAS EVER HELD — one area's, or every area's.
     *
     * The denominator that tells a QUIET MORNING from NO REGISTER. An
     * organization with a register and nothing open has measured and found
     * nought; one that has never filed anything has measured nothing at all,
     * and the two are drawn differently wherever this module publishes a
     * figure.
     *
     * A count in SQL rather than rows in PHP, for the reason
     * {@see statusTallyFor()} is: the set is the whole register, and an
     * installation with ten years of filings would otherwise load ten years
     * to decide whether to draw a card.
     */
    public function countByScope(?string $areaUuid): int
    {
        $qb = self::narrowedTo($this->createQueryBuilder('i'), $areaUuid)->select('COUNT(i.id)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * The rows with the taxonomy and the money already loaded — the shape
     * every performance figure is read off, because the plates of a period
     * are readings of the same rows and asking once per plate is how two
     * figures on one card come to disagree.
     */
    private function performanceRows(): QueryBuilder
    {
        return $this->createQueryBuilder('i')
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.kind', 'k')->addSelect('k')
            ->leftJoin('i.money', 'm')->addSelect('m');
    }

    /** One area, named by its uuid, or every area when null. */
    private static function narrowedTo(QueryBuilder $qb, ?string $areaUuid): QueryBuilder
    {
        if (null !== $areaUuid) {
            $qb->join('i.area', 'sa')
                ->andWhere('sa.uuid = :scope_area')
                ->setParameter('scope_area', Uuid::fromString($areaUuid), 'uuid');
        }

        return $qb;
    }

    /*
     * ── WHAT A ZONE'S FIGURES ASK ───────────────────────────────────────────
     *
     * Three questions, three queries, EACH ONE ANSWERING FOR THE WHOLE SET OF
     * ZONES AT ONCE — the zone seam hands a provider every zone the caller is
     * about to draw precisely so nothing runs a query per zone.
     *
     * Raw SQL, for the reason {@see IncidentZoneLocator} is raw SQL: DQL has no
     * ST_Contains. Every table and column name is read from Doctrine's metadata
     * rather than spelled out, because `zone` is AreaBundle's table and an
     * installation may name its columns with a naming strategy of its own.
     *
     * THE POINT DECIDES, NOT THE STAMP. `incident.zone_id` is what the locator
     * wrote at filing; these count what the ground holds NOW, so a zone set
     * redrawn after an incident was filed reports the geography as it is rather
     * than as it was. `position` is NOT NULL on this table, so every incident
     * is somewhere — a point inside no zone simply matches no row.
     */

    /**
     * HOW MANY WERE FILED ON EACH ZONE'S GROUND in a window, keyed by zone uuid.
     *
     * A zone with nothing is absent from the answer rather than present at zero:
     * the caller renders an absence as an absence.
     *
     * @param list<string> $zoneUuids
     *
     * @return array<string, int>
     */
    public function countFiledByZoneBetween(array $zoneUuids, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        if ([] === $zoneUuids) {
            return [];
        }

        $incident = $this->getClassMetadata();
        $sql = \sprintf(
            'SELECT %s, COUNT(i.%s) AS n %s WHERE z.%s IN (:zones) AND i.%s >= :from AND i.%s < :until GROUP BY z.%s',
            $this->zoneKeySelect(),
            $incident->getSingleIdentifierColumnName(),
            $this->zoneGround(),
            $this->zoneUuidColumn(),
            $reportedAt = $incident->getColumnName('reportedAt'),
            $reportedAt,
            $this->zoneUuidColumn(),
        );

        /** @var list<array{ref: string, n: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, [
            'zones' => $zoneUuids,
            'from' => $from,
            'until' => $until,
        ], [
            'zones' => ArrayParameterType::STRING,
            'from' => Types::DATETIME_IMMUTABLE,
            'until' => Types::DATETIME_IMMUTABLE,
        ]);

        return self::tally($rows);
    }

    /**
     * HOW MANY WERE STILL OPEN ON EACH ZONE'S GROUND at one instant, keyed by
     * zone uuid.
     *
     * OPEN IS RECONSTRUCTED FROM THE CLOCK, not read off `status`: a column says
     * where an incident is today, and the question here is where it was when the
     * period closed. It was open at that instant if it had been filed and had
     * neither been resolved nor closed yet — which is what the workflow's two
     * timestamps record ({@see \Uhifadhi\Incident\Service\IncidentTransitionService::apply()}).
     * That also means the set is not "this month's filings": work filed long
     * before the window and never finished is exactly what a backlog is.
     *
     * @param list<string> $zoneUuids
     *
     * @return array<string, int>
     */
    public function countOpenByZoneAt(array $zoneUuids, \DateTimeImmutable $at): array
    {
        if ([] === $zoneUuids) {
            return [];
        }

        $incident = $this->getClassMetadata();
        $sql = \sprintf(
            'SELECT %s, COUNT(i.%s) AS n %s WHERE z.%s IN (:zones)'
            .' AND i.%s < :at AND (i.%s IS NULL OR i.%s >= :at) AND (i.%s IS NULL OR i.%s >= :at) GROUP BY z.%s',
            $this->zoneKeySelect(),
            $incident->getSingleIdentifierColumnName(),
            $this->zoneGround(),
            $this->zoneUuidColumn(),
            $incident->getColumnName('reportedAt'),
            $resolvedAt = $incident->getColumnName('resolvedAt'),
            $resolvedAt,
            $closedAt = $incident->getColumnName('closedAt'),
            $closedAt,
            $this->zoneUuidColumn(),
        );

        /** @var list<array{ref: string, n: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, [
            'zones' => $zoneUuids,
            'at' => $at,
        ], [
            'zones' => ArrayParameterType::STRING,
            'at' => Types::DATETIME_IMMUTABLE,
        ]);

        return self::tally($rows);
    }

    /**
     * THE MONEY PUT ON THE RECORD ON EACH ZONE'S GROUND in a window, by
     * direction, keyed by zone uuid.
     *
     * The COALESCE is {@see IncidentMoney::payable()}'s
     * own fallback chain — approved, then assessed, then claimed — said in SQL,
     * so a zone card and a performance plate cannot disagree about what a fine
     * was; if one changes the other must. Windowed by when the INCIDENT was
     * filed, for the reason {@see moneyAssessedBetween()} is: a figure has no
     * timestamp of its own.
     *
     * @param list<string> $zoneUuids
     *
     * @return array<string, array<string, int>> zone uuid => direction value => the sum signed off
     */
    public function moneyByZoneBetween(array $zoneUuids, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        if ([] === $zoneUuids) {
            return [];
        }

        $incident = $this->getClassMetadata();
        $money = $this->getEntityManager()->getClassMetadata(IncidentMoney::class);
        $sql = \sprintf(
            'SELECT %s, m.%s AS direction, SUM(COALESCE(m.%s, m.%s, m.%s, 0)) AS total %s JOIN %s m ON m.%s = i.%s'
            .' WHERE z.%s IN (:zones) AND i.%s >= :from AND i.%s < :until GROUP BY z.%s, m.%s',
            $this->zoneKeySelect(),
            $money->getColumnName('direction'),
            $money->getColumnName('approved'),
            $money->getColumnName('assessed'),
            $money->getColumnName('claimed'),
            $this->zoneGround(),
            $money->getTableName(),
            $money->getSingleAssociationJoinColumnName('incident'),
            $incident->getSingleIdentifierColumnName(),
            $this->zoneUuidColumn(),
            $reportedAt = $incident->getColumnName('reportedAt'),
            $reportedAt,
            $this->zoneUuidColumn(),
            $money->getColumnName('direction'),
        );

        /** @var list<array{ref: string, direction: string, total: int|string|null}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, [
            'zones' => $zoneUuids,
            'from' => $from,
            'until' => $until,
        ], [
            'zones' => ArrayParameterType::STRING,
            'from' => Types::DATETIME_IMMUTABLE,
            'until' => Types::DATETIME_IMMUTABLE,
        ]);

        $byZone = [];
        foreach ($rows as $row) {
            $byZone[$row['ref']][$row['direction']] = (int) $row['total'];
        }

        return $byZone;
    }

    /**
     * The ground itself: every incident whose point falls inside one of the
     * named zones, narrowed first to the area that zone subdivides so the
     * spatial test runs over an indexed set rather than the whole register.
     */
    private function zoneGround(): string
    {
        $zone = $this->getEntityManager()->getClassMetadata(Zone::class);
        $incident = $this->getClassMetadata();

        return \sprintf(
            'FROM %s z JOIN %s i ON i.%s = z.%s AND ST_Contains(z.%s, i.%s)',
            $zone->getTableName(),
            $incident->getTableName(),
            $incident->getSingleAssociationJoinColumnName('area'),
            $zone->getSingleAssociationJoinColumnName('area'),
            $zone->getColumnName('geom'),
            $incident->getColumnName('position'),
        );
    }

    /** The zone each row is about, under the one name every reader here expects. */
    private function zoneKeySelect(): string
    {
        return \sprintf('z.%s AS ref', $this->zoneUuidColumn());
    }

    private function zoneUuidColumn(): string
    {
        return $this->getEntityManager()->getClassMetadata(Zone::class)->getColumnName('uuid');
    }

    /*
     * ── WHAT A STATION'S FIGURES ASK ────────────────────────────────────────
     *
     * Two questions, two queries, EACH ONE ANSWERING FOR EVERY STATION AT ONCE,
     * for the reason the zone questions do: the seam hands a provider the whole
     * set precisely so nothing runs a query per post.
     *
     * A POST IS A POINT AND HAS NO GROUND, so "here" is a distance and the
     * distance is the caller's — this file is told the radius in metres and
     * never decides it. `ST_DWithin` ON GEOGRAPHY, so the radius is metres on
     * the spheroid rather than degrees of a grid, which at these latitudes are
     * not the same thing in the two directions.
     *
     * NARROWED TO THE POST'S OWN AREA FIRST, both because a station's figures
     * are its area's and because it puts the spatial test over an indexed set
     * instead of the whole register.
     *
     * A RADIUS IS NOT A PARTITION. Two posts 15 km apart share the ground
     * between them, so one incident may count for both — which is the honest
     * reading of "incidents near this post" and the reason these counts are
     * never summed into an area total.
     */

    /**
     * HOW MANY WERE FILED WITHIN THE RADIUS OF EACH POST in a window, keyed by
     * station uuid.
     *
     * A post with nothing near it is absent from the answer rather than present
     * at zero: the caller renders an absence as an absence.
     *
     * @param list<string> $stationUuids
     * @param int          $radiusM      how far from the post still counts as near it, in metres
     *
     * @return array<string, int>
     */
    public function countFiledNearStationsBetween(array $stationUuids, int $radiusM, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        if ([] === $stationUuids) {
            return [];
        }

        $incident = $this->getClassMetadata();
        $sql = \sprintf(
            'SELECT %s, COUNT(i.%s) AS n %s WHERE s.%s IN (:stations) AND i.%s >= :from AND i.%s < :until GROUP BY s.%s',
            $this->stationKeySelect(),
            $incident->getSingleIdentifierColumnName(),
            $this->stationGround(),
            $this->stationUuidColumn(),
            $reportedAt = $incident->getColumnName('reportedAt'),
            $reportedAt,
            $this->stationUuidColumn(),
        );

        /** @var list<array{ref: string, n: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, [
            'stations' => $stationUuids,
            'radius' => $radiusM,
            'from' => $from,
            'until' => $until,
        ], [
            'stations' => ArrayParameterType::STRING,
            'radius' => Types::INTEGER,
            'from' => Types::DATETIME_IMMUTABLE,
            'until' => Types::DATETIME_IMMUTABLE,
        ]);

        return self::tally($rows);
    }

    /**
     * HOW MANY WITHIN THE RADIUS OF EACH POST WERE STILL OPEN at one instant,
     * keyed by station uuid.
     *
     * OPEN IS RECONSTRUCTED FROM THE CLOCK, exactly as {@see countOpenByZoneAt()}
     * does it and for the same reason: `status` says where an incident is today,
     * and the question is where it was when the period closed. Whenever it was
     * filed — work older than the window and still unfinished is what a backlog
     * is.
     *
     * @param list<string> $stationUuids
     * @param int          $radiusM      how far from the post still counts as near it, in metres
     *
     * @return array<string, int>
     */
    public function countOpenNearStationsAt(array $stationUuids, int $radiusM, \DateTimeImmutable $at): array
    {
        if ([] === $stationUuids) {
            return [];
        }

        $incident = $this->getClassMetadata();
        $sql = \sprintf(
            'SELECT %s, COUNT(i.%s) AS n %s WHERE s.%s IN (:stations)'
            .' AND i.%s < :at AND (i.%s IS NULL OR i.%s >= :at) AND (i.%s IS NULL OR i.%s >= :at) GROUP BY s.%s',
            $this->stationKeySelect(),
            $incident->getSingleIdentifierColumnName(),
            $this->stationGround(),
            $this->stationUuidColumn(),
            $incident->getColumnName('reportedAt'),
            $resolvedAt = $incident->getColumnName('resolvedAt'),
            $resolvedAt,
            $closedAt = $incident->getColumnName('closedAt'),
            $closedAt,
            $this->stationUuidColumn(),
        );

        /** @var list<array{ref: string, n: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, [
            'stations' => $stationUuids,
            'radius' => $radiusM,
            'at' => $at,
        ], [
            'stations' => ArrayParameterType::STRING,
            'radius' => Types::INTEGER,
            'at' => Types::DATETIME_IMMUTABLE,
        ]);

        return self::tally($rows);
    }

    /**
     * The neighbourhood itself: every incident of the post's own area whose
     * point lies within the radius of the post's point, measured on the
     * spheroid. Both tables and both column names are AreaBundle's and this
     * module's own metadata, never spelled out — an installation may name
     * either with a naming strategy of its own.
     */
    private function stationGround(): string
    {
        $station = $this->getEntityManager()->getClassMetadata(Station::class);
        $incident = $this->getClassMetadata();

        return \sprintf(
            'FROM %s s JOIN %s i ON i.%s = s.%s AND ST_DWithin(i.%s::geography, s.%s::geography, :radius)',
            $station->getTableName(),
            $incident->getTableName(),
            $incident->getSingleAssociationJoinColumnName('area'),
            $station->getSingleAssociationJoinColumnName('area'),
            $incident->getColumnName('position'),
            $station->getColumnName('point'),
        );
    }

    /** The post each row is about, under the one name every reader here expects. */
    private function stationKeySelect(): string
    {
        return \sprintf('s.%s AS ref', $this->stationUuidColumn());
    }

    private function stationUuidColumn(): string
    {
        return $this->getEntityManager()->getClassMetadata(Station::class)->getColumnName('uuid');
    }

    /**
     * Rows of "one uuid, one count" as every grouped question here returns
     * them, whatever ground they were grouped over.
     *
     * @param list<array{ref: string, n: int|string}> $rows
     *
     * @return array<string, int>
     */
    private static function tally(array $rows): array
    {
        $tally = [];
        foreach ($rows as $row) {
            $tally[$row['ref']] = (int) $row['n'];
        }

        return $tally;
    }

    /**
     * The most recent evidence across every incident the filter matches — the
     * "latest evidence" widget.
     *
     * Rooted on the EVIDENCE and joined back to the incident, not the other way
     * round: DQL cannot select a joined entity without its root, and the widget
     * wants the twelve newest photographs in the area, never the photographs of
     * the twelve newest incidents. Those are different lists.
     *
     * @return list<\Uhifadhi\Incident\Entity\IncidentEvidence>
     */
    public function latestEvidence(IncidentFilter $filter, int $limit): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('ev')
            ->from(\Uhifadhi\Incident\Entity\IncidentEvidence::class, 'ev')
            ->join('ev.incident', 'i')->addSelect('i')
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.kind', 'k')->addSelect('k')
            ->orderBy('ev.capturedAt', 'DESC')
            ->addOrderBy('ev.id', 'DESC')
            ->setMaxResults($limit);

        /** @var list<\Uhifadhi\Incident\Entity\IncidentEvidence> $evidence */
        $evidence = $this->applyFilter($qb, $filter)->getQuery()->getResult();

        return $evidence;
    }

    /**
     * THE ONE QUERY EVERYTHING ELSE IS BUILT ON: the filter, applied once, with
     * the taxonomy joined because every screen prints the kind beside the row.
     */
    private function filtered(IncidentFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('i')
            ->join('i.subcategory', 's')
            ->join('s.kind', 'k');

        return $this->applyFilter($qb, $filter);
    }

    /**
     * The same query with the taxonomy and the money ALREADY LOADED — what the
     * dashboard reads. Every figure on that screen is computed from these rows,
     * so a lazy association here would be a query per widget per incident.
     */
    private function loaded(IncidentFilter $filter): QueryBuilder
    {
        return $this->filtered($filter)
            ->addSelect('s')
            ->addSelect('k')
            ->leftJoin('i.money', 'm')->addSelect('m')
            ->leftJoin('i.zone', 'lz')->addSelect('lz');
    }

    private function applyFilter(QueryBuilder $qb, IncidentFilter $filter): QueryBuilder
    {
        $qb->andWhere('i.area = :area')->setParameter('area', $filter->area);

        if (null !== $filter->from) {
            $qb->andWhere('i.reportedAt >= :from')->setParameter('from', $filter->from);
        }
        if (null !== $filter->to) {
            $qb->andWhere('i.reportedAt < :to')->setParameter('to', $filter->to);
        }
        if ([] !== $filter->kindCodes) {
            $qb->andWhere('k.code IN (:kindCodes)')->setParameter('kindCodes', $filter->kindCodes);
        }
        if ([] !== $filter->statuses) {
            $qb->andWhere('i.status IN (:statuses)')
                ->setParameter('statuses', array_map(static fn ($s) => $s->value, $filter->statuses));
        }
        if (null !== $filter->zoneName) {
            $qb->join('i.zone', 'fz')->andWhere('fz.name = :zoneName')->setParameter('zoneName', $filter->zoneName);
        }
        if (null !== $filter->search && '' !== $filter->search) {
            // Matched against what a person can READ on a row — the reference (its
            // id), the title (its name), the narrative (what was reported) and the
            // kind it was filed under — the same fields the patrols library
            // searches. `k` is always joined (see filtered()/latestEvidence());
            // the zone is not, so "place" is left to its own chip rather than a
            // join that would break the callers that never join it.
            $qb->andWhere('LOWER(i.reference) LIKE :search OR LOWER(i.title) LIKE :search OR LOWER(i.narrative) LIKE :search OR LOWER(k.label) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($filter->search).'%');
        }

        return $qb;
    }
}
