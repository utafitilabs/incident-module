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

namespace Uhifadhi\Incident\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AtlasBundle\Map\MapBuilder;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;
use Uhifadhi\Bundle\AtlasBundle\Model\Ground;
use Uhifadhi\Bundle\AtlasBundle\Model\LegendItem;
use Uhifadhi\Contracts\Atlas\PlatePalette;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\TaxonomyKind;
use Uhifadhi\Incident\Entity\TaxonomySubcategory;
use Uhifadhi\Incident\Enum\IncidentSeverityEnum;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Model\HousePalette;
use Uhifadhi\Incident\Service\IncidentMapService;

/**
 * WHERE EVERY INCIDENT WAS FILED, STATED IN PHP.
 *
 * One layer per category, each in that category's own hue with a legend row
 * that switches it, and the area's zones underneath as context. The module
 * writes no map JavaScript: the atlas draws what this states.
 *
 * The geometry arrives as the text the geometry column returns. Anything
 * unusable is simply not drawn — a position that will not parse is one mark
 * missing, never a page that fails.
 */
final class IncidentMapServiceTest extends TestCase
{
    private const string BOUNDARY = '{"type":"Polygon","coordinates":[[[-29.5,-3.2],[-29.4,-3.2],[-29.4,-3.1],[-29.5,-3.1],[-29.5,-3.2]]]}';
    private const string ZONE = '{"type":"Polygon","coordinates":[[[-29.48,-3.18],[-29.44,-3.18],[-29.44,-3.14],[-29.48,-3.14],[-29.48,-3.18]]]}';

    public function testTheBoundaryIsDrawnTheOneWayThePlatformDrawsIt(): void
    {
        $map = self::compose(self::BOUNDARY);

        self::assertSame(['geojson' => json_decode(self::BOUNDARY, true), 'scrim' => true], $map->toArray()['boundary']);
    }

    public function testAnAreaWithNoBoundaryStillGetsARealMap(): void
    {
        self::assertNull(self::compose(null)->toArray()['boundary']);
    }

    public function testBoundaryTextThatWillNotParseIsSimplyNotDrawn(): void
    {
        self::assertNull(self::compose('not json')->toArray()['boundary']);
    }

    /**
     * ONE LAYER PER CATEGORY, in the taxonomy's own order, each carrying the
     * house position that category is drawn in everywhere else — the token,
     * never a colour of this module's own.
     */
    public function testEachCategoryIsItsOwnLayerInItsOwnHue(): void
    {
        $layers = self::compose(self::BOUNDARY)->toArray()['layers'];

        self::assertSame(
            [Ground::ZONES_LAYER_ID, 'incident.poaching', 'incident.mortality'],
            array_column($layers, 'id'),
        );
        self::assertSame(HousePalette::token(1), $layers[1]['swatch']);
        self::assertSame(HousePalette::token(4), $layers[2]['swatch']);
        self::assertSame('point', $layers[1]['shape']);
    }

    public function testAnIncidentIsDrawnOnItsOwnCategorysLayer(): void
    {
        $layers = self::compose(self::BOUNDARY)->toArray()['layers'];

        self::assertCount(2, self::featuresOf($layers[1]));
        self::assertCount(1, self::featuresOf($layers[2]));
    }

    /**
     * A CATEGORY WITH NOTHING FILED STILL SHIPS ITS ROW. A legend that comes
     * and goes with the data is a legend nobody can read: "compensation · 0" is
     * an answer, a missing row is a question.
     */
    public function testACategoryWithNothingFiledKeepsItsRowSwitchedOff(): void
    {
        $layers = self::compose(self::BOUNDARY, categories: [
            ['slug' => 'compensation', 'label' => 'Compensation', 'cat' => 3],
        ])->toArray()['layers'];

        self::assertSame('incident.compensation', $layers[1]['id']);
        self::assertSame([], self::featuresOf($layers[1]));
        self::assertFalse($layers[1]['visible']);
    }

    public function testEveryLegendRowCountsWhatItsLayerDraws(): void
    {
        $legend = self::compose(self::BOUNDARY)->legend();

        self::assertSame(['Boundary', 'Zones', 'Poaching', 'Mortality'], array_map(static fn (LegendItem $i) => $i->label, $legend));
        self::assertSame([null, 1, 2, 1], array_map(static fn (LegendItem $i) => $i->count, $legend));
        self::assertSame([AtlasMap::BOUNDARY_LAYER_ID, Ground::ZONES_LAYER_ID, 'incident.poaching', 'incident.mortality'], array_map(static fn (LegendItem $i) => $i->layerId, $legend));
    }

    /** Every category row sits under one heading, so the plate reads as this module's. */
    public function testTheCategoryRowsShareOneHeading(): void
    {
        $legend = self::compose(self::BOUNDARY)->legend();

        self::assertSame(IncidentMapService::GROUP, $legend[2]->group);
        self::assertSame(IncidentMapService::GROUP, $legend[3]->group);
    }

    /**
     * THE AREA'S GROUND IS THE ATLAS'S: the boundary row then "Zones · N"
     * under "The area", the zones the first layer, in the atlas's quiet line.
     * This module names no zone swatch.
     */
    public function testThePlateStandsOnTheAreasGround(): void
    {
        $map = self::compose(self::BOUNDARY);
        $legend = $map->legend();

        self::assertSame([Ground::GROUP, Ground::GROUP], [$legend[0]->group, $legend[1]->group]);
        self::assertSame(['Boundary', 'Zones'], [$legend[0]->label, $legend[1]->label]);
        self::assertSame(1, $legend[1]->count);
        self::assertSame(PlatePalette::DIM, $map->toArray()['layers'][0]['swatch']);
    }

    public function testAnAreaWithNoBoundaryStillStatesItsZonesRow(): void
    {
        $legend = self::compose(null)->legend();

        self::assertSame('Zones', $legend[0]->label);
        self::assertSame(Ground::GROUP, $legend[0]->group);
    }

    /**
     * The zones are the area's, drawn as quiet outlines with their names on
     * them — the plate draws a feature that names itself as a halo label.
     */
    public function testEachZoneTravelsWithItsNameOnTheFeature(): void
    {
        $zones = self::compose(self::BOUNDARY)->toArray()['layers'][0];

        self::assertSame(Ground::ZONES_LAYER_ID, $zones['id']);
        self::assertSame('line', $zones['shape']);
        self::assertSame(
            [['type' => 'Feature', 'properties' => ['label' => 'The northern block'], 'geometry' => json_decode(self::ZONE, true)]],
            self::featuresOf($zones),
        );
    }

    public function testAnAreaWithNoZonesStillStatesTheRow(): void
    {
        $zones = self::compose(self::BOUNDARY, zones: [])->toArray()['layers'][0];

        self::assertSame([], self::featuresOf($zones));
        self::assertFalse($zones['visible']);
    }

    /**
     * WHAT A MARK MEANS, AND THE LEGEND SAYS SO: filled is still open, hollow is
     * resolved or closed. Stated as a rule on the incidents' own `open`
     * property, so the atlas draws it and this module ships no map JavaScript.
     */
    public function testAnOpenIncidentIsFilledAndAFinishedOneIsHollow(): void
    {
        self::assertSame(IncidentMapService::OPEN_FILL, self::partOf('style')['fillOpacity']);
        self::assertContains(
            ['property' => 'open', 'values' => [false], 'style' => ['fillOpacity' => 0.0]],
            self::partOf('rules'),
        );
    }

    /** The serious end — high or critical, not high alone — wears a dashed ring. */
    public function testTheSeriousEndWearsADashedRing(): void
    {
        self::assertContains(
            [
                'property' => 'severity',
                'values' => ['high', 'critical'],
                'style' => [
                    'weight' => IncidentMapService::SERIOUS_WEIGHT,
                    'dashArray' => IncidentMapService::SERIOUS_DASH,
                    'radius' => IncidentMapService::SERIOUS_RADIUS,
                ],
            ],
            self::partOf('rules'),
        );
    }

    /** A mark says what it is under the cursor, without being clicked. */
    public function testAMarkSaysWhatItIsOnHover(): void
    {
        self::assertSame('summary', self::markLayer()['tooltip']);
    }

    /** And clicking it opens the case file, which is where the rest of the story is. */
    public function testAMarkOpensTheCaseFile(): void
    {
        $popup = self::partOf('popup');

        self::assertSame('title', $popup['title']);
        self::assertSame('href', $popup['href']);
        self::assertSame(IncidentMapService::CASE_FILE_LINK, $popup['linkLabel']);
    }

    /** A row beside the map spotlights its own mark, and this is what it names it by. */
    public function testAMarkIsSpotlitByItsReference(): void
    {
        self::assertSame('reference', self::markLayer()['featureId']);
    }

    /** The zones underneath are context, so they answer neither a hover nor a click. */
    public function testTheZonesUnderneathSayNothing(): void
    {
        $zones = self::compose(self::BOUNDARY)->toArray()['layers'][0];

        self::assertNull($zones['tooltip']);
        self::assertNull($zones['popup']);
    }

    /**
     * The first category layer — the one every statement about a mark is made
     * on. Narrowed for the analyser.
     *
     * @return array<string, mixed>
     */
    private static function markLayer(): array
    {
        return self::compose(self::BOUNDARY)->toArray()['layers'][1];
    }

    /**
     * One part of that layer's statement, narrowed the same way.
     *
     * @return array<array-key, mixed>
     */
    private static function partOf(string $key): array
    {
        $part = self::markLayer()[$key];
        self::assertIsArray($part);

        return $part;
    }

    /**
     * The features one layer carries, narrowed for the analyser.
     *
     * @param array<string, mixed> $layer
     *
     * @return list<mixed>
     */
    private static function featuresOf(array $layer): array
    {
        $collection = $layer['features'];

        self::assertIsArray($collection);
        self::assertIsList($collection['features'] ?? null);

        return $collection['features'];
    }

    /**
     * WHAT A MARK CARRIES — the properties the atlas reads to decide the hue, the
     * fill, the ring, the hover line and where a click goes. Every one of them is
     * a PROPERTY: the atlas writes the markup, so nothing here is a rendered
     * string.
     */
    public function testAMarkCarriesWhatTheLegendPromises(): void
    {
        $incident = self::anIncident();

        $collection = IncidentMapService::featuresFor([$incident], ['INC-0313' => '/areas/a/modules/incidents/INC-0313']);

        self::assertSame('FeatureCollection', $collection['type']);
        self::assertCount(1, $collection['features']);
        $properties = $collection['features'][0]['properties'];

        // Hue is the CATEGORY: the layer is split on this key and drawn in the
        // house hue the category's POSITION points at — the token, never a
        // colour of this module's own.
        self::assertSame('conflict', $properties['slug']);
        self::assertSame('var(--cat-1)', $properties['colour']);
        // Filled is open; hollow is resolved or closed.
        self::assertTrue($properties['open']);
        // The dashed ring is read off the severity.
        self::assertSame('critical', $properties['severity']);
        // One line on hover, one url on click.
        self::assertSame('INC-0313 · livestock depredation · verified', $properties['summary']);
        self::assertSame('/areas/a/modules/incidents/INC-0313', $properties['href']);
    }

    /** An incident nobody handed a case-file url is drawn without a way onward. */
    public function testAMarkWithNoCaseFileUrlIsStillDrawn(): void
    {
        $collection = IncidentMapService::featuresFor([self::anIncident()]);

        self::assertCount(1, $collection['features']);
        self::assertNull($collection['features'][0]['properties']['href']);
    }

    /** A position that will not parse is one mark missing, never a page that fails. */
    public function testAPositionThatWillNotParseIsSimplyNotDrawn(): void
    {
        self::assertSame([], IncidentMapService::featuresFor([self::anIncident('not json')])['features']);
    }

    private static function anIncident(string $position = '{"type":"Point","coordinates":[-29.55,-3.21]}'): Incident
    {
        $area = new AreaOfInterest()->setSource('test fixture');
        $area->setName('Kifaru Sector');

        $kind = new TaxonomyKind($area, 'conflict', 'Human–wildlife conflict');
        $subcategory = new TaxonomySubcategory($kind, 'livestock-depredation', 'livestock depredation');

        return new Incident(
            $area,
            $subcategory,
            'INC-0313',
            'Lion killed four goats at Riverside',
            $position,
            new \DateTimeImmutable('2026-08-19 07:10:00'),
        )
            ->setStatus(IncidentStatusEnum::Verified)
            ->setSeverity(IncidentSeverityEnum::Critical);
    }

    /**
     * @param list<array{slug: string, label: string, cat: int}>|null $categories
     * @param list<array{name: string|null, geom: string|null}>|null  $zones
     */
    private static function compose(?string $boundary, ?array $categories = null, ?array $zones = null): AtlasMap
    {
        return IncidentMapService::compose(
            new MapBuilder(),
            // The area's answer, as AreaMapPayload::forArea() gives it.
            ['boundary' => $boundary, 'zones' => $zones ?? [['name' => 'The northern block', 'geom' => self::ZONE]]],
            self::collection(),
            $categories ?? [
                ['slug' => 'poaching', 'label' => 'Poaching', 'cat' => 1],
                ['slug' => 'mortality', 'label' => 'Mortality', 'cat' => 4],
            ],
        );
    }

    /**
     * The shape {@see IncidentMapService::featuresFor()} hands over.
     *
     * @return array<string, mixed>
     */
    private static function collection(): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => [
                self::feature('poaching', -29.48, -3.18),
                self::feature('poaching', -29.46, -3.16),
                self::feature('mortality', -29.45, -3.15),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function feature(string $slug, float $lon, float $lat): array
    {
        return [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [$lon, $lat]],
            'properties' => ['slug' => $slug, 'reference' => 'IN-1'],
        ];
    }
}
