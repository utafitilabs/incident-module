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

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;

/**
 * WHICH ZONE IS THIS POINT IN? — the one spatial question this module asks of
 * the host's zones, asked once when an incident is filed rather than on every
 * read.
 *
 * An incident does not move. Recomputing the zone per render would be a spatial
 * join behind every widget on the dashboard, so the answer is stored on the row
 * and this locator runs once.
 *
 * UNZONED IS A FIRST-CLASS ANSWER, and null is how it is said. An organization
 * that has drawn no zones at all is the normal state, so every caller must be
 * able to handle "none" — and none of them may treat it as an error, a missing
 * configuration, or a reason to refuse a report.
 *
 * Raw SQL because DQL has no ST_Contains, and every table and column name is read
 * from Doctrine's metadata rather than spelled out: the host owns Zone and may
 * name its columns with a different naming strategy than this bundle's tests do.
 */
final readonly class IncidentZoneLocator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param string $position the point, as GeoJSON text — the same shape the
     *                         incident's own column holds
     */
    public function locate(AreaOfInterest $area, string $position): ?Zone
    {
        $zone = $this->entityManager->getClassMetadata(Zone::class);
        $areaMeta = $this->entityManager->getClassMetadata(AreaOfInterest::class);

        $sql = \sprintf(
            <<<'SQL'
                SELECT z.%s
                FROM %s z
                WHERE z.%s = :area
                  AND z.%s IS NOT NULL
                  AND ST_Contains(z.%s, ST_SetSRID(ST_GeomFromGeoJSON(:point), 4326))
                ORDER BY z.%s
                LIMIT 1
                SQL,
            $id = $zone->getSingleIdentifierColumnName(),
            $zone->getTableName(),
            $zone->getSingleAssociationJoinColumnName('area'),
            $geom = $zone->getColumnName('geom'),
            $geom,
            $id,
        );

        $zoneId = $this->entityManager->getConnection()->fetchOne($sql, [
            'area' => $area->getId(),
            'point' => $position,
        ], [
            'area' => Types::INTEGER,
            'point' => Types::STRING,
        ]);

        // Sibling zones never share interior (the host's ZoneService holds that
        // invariant), so at most one contains the point and LIMIT 1 loses nothing.
        // A point on a shared edge takes the lower id, deterministically, rather
        // than whichever row the planner returned first.
        //
        // DBAL answers `false` for no row and a scalar otherwise; anything that is
        // not a number means no zone, which is a first-class answer here.
        return is_numeric($zoneId) ? $this->entityManager->find(Zone::class, (int) $zoneId) : null;
    }
}
