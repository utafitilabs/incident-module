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
use Uhifadhi\Incident\Enum\IncidentSeverityEnum;
use Uhifadhi\Incident\Model\IncidentPrefill;
use Uhifadhi\Incident\Model\ReportAnswers;

final class ReportAnswersTest extends TestCase
{
    public function testAPostedFormIsKeptAsItWasTyped(): void
    {
        $answers = ReportAnswers::fromPost(Request::create('/', 'POST', [
            'title' => 'Lion killed four goats',
            'lat' => ' -3.21 ',
            'lng' => '-28.989',
            'severity' => 'high',
            'narrative' => 'At dusk.',
            'blocks' => [
                'species' => ['sex' => 'female'],
                'counts' => ['rows' => [['how_many' => '4'], ['how_many' => '2', 'odd' => ['x']]]],
            ],
        ]), null);

        self::assertSame('Lion killed four goats', $answers->title);
        self::assertSame('-3.21', $answers->latitude);
        self::assertSame(IncidentSeverityEnum::High, $answers->severity);
        self::assertSame('At dusk.', $answers->narrative);
        self::assertSame('female', $answers->block('species', 'sex'));
        self::assertSame('', $answers->block('species', 'age_class'));
        self::assertSame([['how_many' => '4'], ['how_many' => '2']], $answers->rows('counts'));
    }

    public function testABlockThatRepeatsAlwaysHasOneRow(): void
    {
        self::assertSame([[]], new ReportAnswers()->rows('counts'));
    }

    public function testAnUnknownSeverityIsModerate(): void
    {
        $answers = ReportAnswers::fromPost(Request::create('/', 'POST', ['severity' => 'apocalyptic']), null);

        self::assertSame(IncidentSeverityEnum::Moderate, $answers->severity);
    }

    public function testAFreshFormHoldsTheHandOffsGuesses(): void
    {
        $answers = ReportAnswers::fromPrefill(new IncidentPrefill(latitude: -3.2, longitude: -29.5, note: 'Tracks.'));

        self::assertSame('Tracks.', $answers->title);
        self::assertSame('Tracks.', $answers->narrative);
        self::assertSame('-3.2', $answers->latitude);
        self::assertTrue($answers->hasPoint());
        self::assertFalse(new ReportAnswers()->hasPoint());
    }
}
