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

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Model\IncidentOrgQuery;

/**
 * THE ORGANIZATION REGISTER'S QUESTION: read off a GET it cannot trust, and
 * written back as the links every option carries.
 */
final class IncidentOrgQueryTest extends TestCase
{
    private const string AREA = '0190a8e0-0000-7000-8000-000000000001';

    public function testItReadsEveryAxisAndDropsWhatItCannotRead(): void
    {
        $query = self::read(['category' => 'poaching', 'status' => 'in_progress', 'department' => 'Protection Service', 'q' => ' snare ']);

        self::assertSame('poaching', $query->category);
        self::assertSame(IncidentStatusEnum::InProgress, $query->status);
        self::assertSame('Protection Service', $query->department);
        self::assertSame('snare', $query->search);
        self::assertTrue($query->isNarrowed());

        $loose = self::read(['status' => 'nowhere', 'category' => '  ']);
        self::assertNull($loose->status);
        self::assertNull($loose->category);
        self::assertFalse($loose->isNarrowed());
    }

    public function testItWritesTheWholeQuestionBackWithTheScopeAndTheMonth(): void
    {
        self::assertSame(
            ['area' => self::AREA, 'category' => 'poaching', 'month' => '2026-08', 'q' => 'snare'],
            self::read(['category' => 'poaching', 'q' => 'snare'])->toQuery(),
        );
    }

    public function testOneOptionChangesOneAxisAndClearsWithNull(): void
    {
        $query = self::read(['category' => 'poaching']);

        self::assertSame(['area' => self::AREA, 'month' => '2026-08', 'category' => 'conflict'], $query->with('category', 'conflict'));
        self::assertSame(['category' => 'poaching', 'month' => '2026-08'], $query->with('area', null));
    }

    /** @param array<string, string> $parameters */
    private static function read(array $parameters): IncidentOrgQuery
    {
        $from = new \DateTimeImmutable('2026-08-01');

        return IncidentOrgQuery::fromRequest(new Request($parameters), self::AREA, $from, $from->modify('+1 month'));
    }
}
