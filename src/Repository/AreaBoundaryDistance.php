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

/**
 * HOW FAR A POINT LIES BEYOND ITS AREA'S BOUNDARY, in metres on the ground —
 * a question for PostGIS, measured on the geography so a metre is a metre at
 * any latitude.
 */
final readonly class AreaBoundaryDistance
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** Nought for a point inside or on the boundary; null where the area has no boundary. */
    public function metresBeyond(AreaOfInterest $area, string $position): ?float
    {
        $meta = $this->entityManager->getClassMetadata(AreaOfInterest::class);

        $sql = \sprintf(
            <<<'SQL'
                SELECT ST_Distance(a.%s::geography, ST_SetSRID(ST_GeomFromGeoJSON(:point), 4326)::geography)
                FROM %s a
                WHERE a.%s = :area
                  AND a.%s IS NOT NULL
                SQL,
            $geom = $meta->getColumnName('geom'),
            $meta->getTableName(),
            $meta->getSingleIdentifierColumnName(),
            $geom,
        );

        $metres = $this->entityManager->getConnection()->fetchOne($sql, [
            'area' => $area->getId(),
            'point' => $position,
        ], [
            'area' => Types::INTEGER,
            'point' => Types::STRING,
        ]);

        return is_numeric($metres) ? (float) $metres : null;
    }
}
