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
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Incident\Model\IncidentPrefill;

/**
 * WHAT THE SOURCE CARD READS.
 *
 * A filing that arrives from an observation shows that observation: its label,
 * its own words, its time and — the part with a right answer — its POSITION, in
 * the same degrees-minutes-seconds notation the observation page prints. Two
 * records that describe the same place must not describe it two different ways,
 * so the notation is pinned here by test.
 */
final class IncidentPrefillTest extends TestCase
{
    /** The design's own example: the observation at 3°12'05"S 29°32'16"W. */
    public function testThePositionReadsAsDegreesMinutesSecondsLikeTheObservationPage(): void
    {
        $prefill = new IncidentPrefill(latitude: -3.2014, longitude: -29.5378);

        self::assertSame('3°12\'05"S 29°32\'16"W', $prefill->positionLabel());
    }

    /** North and east are the other half of the compass, and are not assumed. */
    public function testTheHemispheresAreReadFromTheSignOfEachCoordinate(): void
    {
        $prefill = new IncidentPrefill(latitude: 3.2014, longitude: -35.4622);

        self::assertSame('3°12\'05"N 35°27\'44"W', $prefill->positionLabel());
    }

    /**
     * Seconds that round to sixty carry into the minutes rather than printing
     * an impossible 60" — the whole point of the notation is that it can be read
     * aloud into a radio.
     */
    public function testSecondsThatRoundToSixtyCarryIntoTheMinutes(): void
    {
        // 0.0166666… ≈ 0°00'59.9994" — one ten-thousandth short of a minute.
        $prefill = new IncidentPrefill(latitude: 59.9999 / 3600, longitude: 0.0);

        self::assertSame('0°01\'00"N 0°00\'00"E', $prefill->positionLabel());
    }

    /** No coordinates means no position row on the card — never a half-written one. */
    public function testAPrefillWithoutCoordinatesHasNoPositionLabel(): void
    {
        self::assertNull(new IncidentPrefill(latitude: -3.2014)->positionLabel());
        self::assertNull(new IncidentPrefill()->positionLabel());
    }

    /**
     * THE SOURCE, NAMED IN WORDS. The hand-off is a query string and the module on
     * the other side of it chooses the token, so an unrecognised one is printed
     * as-is rather than swallowed: the card says where the report came from even
     * when this module has never heard of that kind of source.
     */
    public function testAKnownSourceIsNamedAndAnUnknownOneIsStillPrinted(): void
    {
        // ONE TOKEN ON THE WIRE, and it is the one patrol actually sends: the
        // query string's `source` names the SENDING MODULE, singular. The enum names
        // WHERE A REPORT CAME FROM, which is a stored vocabulary that does not
        // get renamed to tidy a query string — so the two are mapped, and both
        // spellings print the same badge rather than one of them printing raw.
        self::assertSame('patrol observation', new IncidentPrefill(source: 'patrol')->sourceLabel());
        self::assertSame('patrol observation', new IncidentPrefill(source: 'patrols')->sourceLabel());
        self::assertSame('patrol observation', new IncidentPrefill(source: 'patrol_observation')->sourceLabel());
        self::assertSame('camera trap', new IncidentPrefill(source: 'camera-trap')->sourceLabel());
        self::assertSame('linked record', new IncidentPrefill()->sourceLabel());
    }

    /** Provenance is the record plus its label — one without the other ties nothing. */
    public function testProvenanceNeedsBothTheRecordAndItsLabel(): void
    {
        $record = Uuid::v7();

        self::assertTrue(new IncidentPrefill(record: $record, label: 'OBS-02 · lion tracks')->hasProvenance());
        self::assertFalse(new IncidentPrefill(record: $record)->hasProvenance());
        self::assertFalse(new IncidentPrefill(label: 'OBS-02 · lion tracks')->hasProvenance());
    }

    /**
     * THE HAND-OFF SURVIVES THE POST. The form's action carries the prefill back to
     * the server, because the container the flow re-opens in, the source card a
     * refused filing comes back with and the provenance written onto the incident
     * are all read from the query.
     */
    public function testThePrefillGoesBackOutAsTheQueryItArrivedAs(): void
    {
        $record = Uuid::v7();

        $query = new IncidentPrefill(
            record: $record,
            label: 'observation 2 of patrol P-0142',
            backUrl: '/areas/x/modules/patrols/observation/2',
            source: 'patrol_observation',
            occurredAt: new \DateTimeImmutable('2026-08-22T08:15:00+03:00'),
            latitude: -3.2014,
            longitude: -29.5378,
            subcategorySlug: 'livestock-depredation',
            note: 'Fresh lion tracks 400 m from the bomas.',
        )->toQuery();

        self::assertSame($record->toRfc4122(), $query['record']);
        self::assertSame('observation 2 of patrol P-0142', $query['label']);
        self::assertSame('/areas/x/modules/patrols/observation/2', $query['back']);
        self::assertSame('patrol_observation', $query['source']);
        // The moment in ITS OWN zone, so a round trip cannot silently restate a
        // field observation in UTC.
        self::assertSame('2026-08-22T08:15:00+03:00', $query['at']);
        self::assertSame('-3.2014', $query['lat']);
        self::assertSame('-29.5378', $query['lng']);
        self::assertSame('livestock-depredation', $query['category']);
        self::assertSame('Fresh lion tracks 400 m from the bomas.', $query['note']);
    }

    /** Nothing arrived, so nothing is echoed — and junk that was dropped stays dropped. */
    public function testAPrefillThatUnderstoodNothingEchoesNothing(): void
    {
        self::assertSame([], new IncidentPrefill()->toQuery());
    }

    /**
     * WHOSE RECORD IT WAS AND WHOSE EYES SAW IT, read the same way the label is:
     * off the hand-off's query string. The rail beside the report form states the
     * record it is filing about — which patrol, which observer, when — and neither
     * bundle may name the other's classes to find that out, so the sending module
     * puts it on the wire beside the label it already sends.
     */
    public function testTheHandOffCarriesThePatrolAndTheObserverBesideTheLabel(): void
    {
        $prefill = IncidentPrefill::fromRequest(Request::create('/', 'GET', [
            'patrol' => 'P-0142 · foot patrol · Southern Valley',
            'ranger' => 'S. Laizer · ranger',
        ]));

        self::assertSame('P-0142 · foot patrol · Southern Valley', $prefill->patrol);
        self::assertSame('S. Laizer · ranger', $prefill->ranger);
        self::assertSame(
            ['patrol' => 'P-0142 · foot patrol · Southern Valley', 'ranger' => 'S. Laizer · ranger'],
            $prefill->toQuery(),
        );
    }

    /**
     * AND A HAND-OFF THAT SENT NEITHER IS COMPLETE. A module with no patrol and no
     * named observer — or one that predates the two parameters — hands over what it
     * has, and the rail simply draws no row for what it was not told.
     */
    public function testAHandOffWithNoPatrolOrObserverSimplyHasNone(): void
    {
        $prefill = IncidentPrefill::fromRequest(Request::create('/', 'GET', ['patrol' => '  ', 'ranger' => '']));

        self::assertNull($prefill->patrol);
        self::assertNull($prefill->ranger);
        self::assertSame([], $prefill->toQuery());
    }
}
