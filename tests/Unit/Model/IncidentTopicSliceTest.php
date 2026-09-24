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

namespace Uhifadhi\Incident\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Incident\Model\IncidentTopicSlice;

/**
 * THE GROUND ONE ROW OF THE TOPIC'S MATRIX IS ABOUT — and the whole of what a
 * department contributes to a figure.
 *
 * FIGURES FOLLOW SCOPE, NOT PEOPLE. A department never filters incidents by
 * who recorded them; it only says WHICH GROUND it reads, and this is the
 * intersection of that with the page's own scope. Two departments scoped to
 * the same area therefore resolve to the same slice, which is exactly why
 * they read identical figures.
 */
#[CoversClass(IncidentTopicSlice::class)]
final class IncidentTopicSliceTest extends TestCase
{
    private const string NORTH = '0198f0a0-0000-7000-8000-00000000north';
    private const string SOUTH = '0198f0a0-0000-7000-8000-00000000south';

    /** The organization's page: an org-wide department reads every area. */
    public function testAnOrgWideDepartmentOnTheOrganizationsPageRollsUpEveryArea(): void
    {
        $slice = IncidentTopicSlice::of(null, null);

        self::assertNotNull($slice);
        self::assertNull($slice->areaUuid);
        self::assertTrue($slice->isRollUp());
    }

    /** The organization's page: an area-level department reads its own area. */
    public function testAnAreaLevelDepartmentOnTheOrganizationsPageReadsItsOwnArea(): void
    {
        $slice = IncidentTopicSlice::of(null, self::NORTH);

        self::assertNotNull($slice);
        self::assertSame(self::NORTH, $slice->areaUuid);
        self::assertFalse($slice->isRollUp());
    }

    /** An area's page: an org-wide department reads that area, not every area. */
    public function testAnOrgWideDepartmentOnAnAreasPageReadsThatAreaOnly(): void
    {
        $slice = IncidentTopicSlice::of(self::NORTH, null);

        self::assertNotNull($slice);
        self::assertSame(self::NORTH, $slice->areaUuid);
    }

    /** An area's page: its own department reads it too — the same slice. */
    public function testTwoDepartmentsReadingOneAreaResolveToTheSameGround(): void
    {
        $orgWide = IncidentTopicSlice::of(self::NORTH, null);
        $areaLevel = IncidentTopicSlice::of(self::NORTH, self::NORTH);

        self::assertEquals($orgWide, $areaLevel, 'Identical ground is identical figures — that is the rule.');
    }

    /**
     * A DEPARTMENT CONFINED SOMEWHERE ELSE IS NOT A ROW OF THIS PAGE. Null,
     * not an empty slice: an absent row and a row of dashes say different
     * things.
     */
    public function testADepartmentOfAnotherAreaIsNoRowOfThisPage(): void
    {
        self::assertNull(IncidentTopicSlice::of(self::NORTH, self::SOUTH));
    }
}
