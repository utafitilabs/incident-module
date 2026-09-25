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

namespace Uhifadhi\Incident\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Service\AreaMapPayload;
use Uhifadhi\Bundle\AtlasBundle\Map\MapBuilderInterface;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;
use Uhifadhi\Bundle\AtlasBundle\Model\FeaturePopup;
use Uhifadhi\Bundle\AtlasBundle\Model\GeoJsonLayer;
use Uhifadhi\Bundle\AtlasBundle\Model\Ground;
use Uhifadhi\Bundle\AtlasBundle\Model\LayerShape;
use Uhifadhi\Bundle\AtlasBundle\Model\LayerStyle;
use Uhifadhi\Bundle\AtlasBundle\Model\StyleRule;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\TaxonomyKind;
use Uhifadhi\Incident\Model\HousePalette;

/**
 * WHERE EVERY INCIDENT WAS FILED, STATED IN PHP.
 *
 * ONE BUILDER for every incidents map there is — the dashboard's plate, the
 * map+results plate, and the single point on a case file — so the same mark
 * cannot mean two things on two screens.
 *
 * The module writes no map JavaScript. It says what is on the map and the atlas
 * draws it: the deployment's imagery, the boundary's one treatment, the control
 * stack, the legend with a switch per row, and fullscreen.
 *
 * ONE LAYER PER KIND, because a kind is what a person switches on and off.
 * What it wears is the kind's POSITION in the area's list, published as the
 * house token ({@see HousePalette}) — the same position the chips read — so a
 * marker and a chip cannot drift apart, and neither names a colour.
 *
 * AND A MARK MEANS WHAT THE LEGEND PROMISES: filled is still open, hollow is
 * resolved or closed, a dashed ring is the serious end. All three are stated —
 * a base style and rules on the incidents' own properties — and the atlas
 * evaluates them per feature. What a mark says on hover and what it opens on a
 * click are stated the same way, as the names of properties the payload
 * carries; the atlas writes the markup and escapes the values, so nothing here
 * is ever rendered HTML on somebody's map.
 *
 * THE GROUND UNDER THEM IS THE AREA'S, not this module's. The area answers
 * what its ground is (`AreaMapPayload::forArea()`: the boundary and the zones)
 * and the atlas draws it as a {@see Ground}: the zones as quiet outlines
 * wearing their names under every mark, and the legend opening on "The area"
 * with the boundary row and "Zones · N" — the one shape every module's plate of
 * the area wears.
 *
 * @see vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/AreaBundle/Service/AreaMapPayload.php
 * @see vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/AtlasBundle/docs/components.md
 */
final readonly class IncidentMapService
{
    /** The heading every kind's row sits under, so the plate reads as this module's. */
    public const string GROUP = 'Incidents';

    /**
     * WHAT A MARK MEANS, AND THE LEGEND SAYS EXACTLY THIS.
     *
     *   hue          the kind's position in the area's list — the layer's swatch
     *   filled       still open · hollow = resolved or closed
     *   dashed ring  the serious end: high OR critical, never high alone
     *
     * Numbers rather than tokens for the geometry of a mark; the COLOUR is the
     * house's, handed over as the token that names the position
     * ({@see HousePalette}) so the plate repaints it for imagery itself.
     */
    public const float OPEN_FILL = 0.85;
    public const float MARK_RADIUS = 5.5;
    public const float MARK_WEIGHT = 1.6;
    public const float SERIOUS_RADIUS = 7.0;
    public const float SERIOUS_WEIGHT = 2.4;
    public const string SERIOUS_DASH = '3 3';

    /** The property a hover reads: which case, what kind, where it stands. */
    public const string HOVER_PROPERTY = 'summary';

    /** What the popup's one link says. The rest of the story is only on that page. */
    public const string CASE_FILE_LINK = 'Open the case file →';

    /** The route a mark leads to, and the parameter names it is generated with. */
    public const string CASE_FILE_ROUTE = 'incident_show';

    public function __construct(
        private MapBuilderInterface $maps,
        private AreaMapPayload $ground,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * The plate an incidents screen renders: the area's ground, and the
     * incidents handed in, split by kind.
     *
     * The kinds are the CALLER's choice, not the whole vocabulary: a dashboard
     * states every kind it filed under, a case file states the
     * one its incident belongs to.
     *
     * @param list<Incident>     $incidents
     * @param list<TaxonomyKind> $kinds
     */
    public function forArea(AreaOfInterest $area, array $incidents, array $kinds): AtlasMap
    {
        return self::compose(
            $this->maps,
            $this->ground->forArea($area),
            self::featuresFor($incidents, $this->caseFiles($area, $incidents)),
            array_map(static fn (TaxonomyKind $kind): array => [
                'slug' => $kind->getCode(),
                'label' => $kind->getLabel(),
                'cat' => $kind->catIndex(),
            ], $kinds),
        );
    }

    /**
     * The map itself, from plain data.
     *
     * Static and entity-free so the shape of the plate — which layer, which
     * hue, which legend row — is unit-tested without a database behind it.
     *
     * @param array{boundary: string|null, zones: list<array{name: string|null, geom: string|null}>} $ground     the area's answer, from `AreaMapPayload::forArea()`
     * @param array<string, mixed>                                                                   $collection the FeatureCollection {@see featuresFor()} builds
     * @param list<array{slug: string, label: string, cat: int}>                                     $kinds
     */
    public static function compose(
        MapBuilderInterface $maps,
        array $ground,
        array $collection,
        array $kinds,
    ): AtlasMap {
        $map = $maps->createMap();

        // THE AREA'S GROUND, drawn by the atlas: the boundary with its scrim
        // and row, the zones under every mark with "Zones · N".
        $map->ground(Ground::fromGeoJson($ground['boundary'], $ground['zones']));

        $features = $collection['features'] ?? [];
        foreach ($kinds as $kind) {
            $own = \is_array($features) ? array_values(array_filter(
                $features,
                static fn (mixed $feature): bool => \is_array($feature)
                    && \is_array($feature['properties'] ?? null)
                    && ($feature['properties']['slug'] ?? null) === $kind['slug'],
            )) : [];

            $map->addLayer(new GeoJsonLayer(
                id: 'incident.'.$kind['slug'],
                label: $kind['label'],
                features: self::collection($own),
                swatch: HousePalette::token($kind['cat']),
                shape: LayerShape::Point,
                visible: [] !== $own,
                count: \count($own),
                group: self::GROUP,
                style: new LayerStyle(
                    weight: self::MARK_WEIGHT,
                    fillOpacity: self::OPEN_FILL,
                    radius: self::MARK_RADIUS,
                ),
                rules: [
                    // HOLLOW once it is finished. The stroke stays, so a closed
                    // case is still a mark in its kind's hue — it has simply
                    // stopped being something anybody is working on.
                    StyleRule::when('open', false)->fillOpacity(0.0),
                    // And the serious end wears a wider dashed ring, so it reads
                    // across a plate without anybody hovering anything.
                    StyleRule::when('severity', ['high', 'critical'])
                        ->weight(self::SERIOUS_WEIGHT)
                        ->dashArray(self::SERIOUS_DASH)
                        ->radius(self::SERIOUS_RADIUS),
                ],
                tooltip: self::HOVER_PROPERTY,
                popup: new FeaturePopup(
                    title: 'title',
                    lines: ['category', 'zone', 'statusLabel'],
                    href: 'href',
                    linkLabel: self::CASE_FILE_LINK,
                ),
                // What a row elsewhere on the page spotlights a mark by.
                featureId: 'reference',
            ));
        }

        return $map;
    }

    /**
     * WHAT THE MARKS ARE, as a GeoJSON FeatureCollection — every incidents map
     * there is comes through here, so a mark cannot mean two things on two
     * screens.
     *
     * Everything a mark needs is a PROPERTY, and the atlas reads properties and
     * writes the markup; nothing here is ever a rendered string. The meaning is
     * exactly what the legend beside it promises:
     *
     *   hue          = the kind
     *   filled       = still open · hollow = resolved or closed
     *   dashed ring  = the serious end (high or critical)
     *
     * Static and given the urls rather than generating them, so the shape of a
     * mark is pinned against real incidents with no router behind it.
     *
     * @param list<Incident>        $incidents
     * @param array<string, string> $caseFiles reference → the url of that incident's case file; an
     *                                         incident with none is drawn without a way onward
     *
     * @return array{type: string, features: list<array{type: string, geometry: array<string, mixed>, properties: array<string, mixed>}>}
     */
    public static function featuresFor(array $incidents, array $caseFiles = []): array
    {
        $features = [];
        foreach ($incidents as $incident) {
            /** @var array<string, mixed>|null $geometry */
            $geometry = json_decode($incident->getPosition(), true);
            if (!\is_array($geometry)) {
                continue;
            }

            $features[] = [
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => [
                    'reference' => $incident->getReference(),
                    // A map pin's label is a row, so it prints the first line —
                    // the same line the register does.
                    'title' => $incident->headline(),
                    // The key the plate's layers are split by: one layer per
                    // kind, so a legend row switches a kind on and off.
                    'slug' => $incident->getKind()->getCode(),
                    'colour' => HousePalette::token($incident->getKind()->catIndex()),
                    'category' => $incident->getKind()->getLabel(),
                    'subcategory' => $incident->getSubcategory()->getLabel(),
                    'status' => $incident->getStatus()->value,
                    'statusLabel' => $incident->getStatus()->label(),
                    'open' => $incident->getStatus()->isOpen(),
                    'severity' => $incident->getSeverity()->value,
                    'zone' => $incident->zoneLabel(),
                    // The one line a hover prints: which case, what kind, where
                    // it stands — the register's own three facts, composed here
                    // because a tooltip reads ONE property.
                    'summary' => \sprintf(
                        '%s · %s · %s',
                        $incident->getReference(),
                        $incident->getSubcategory()->getLabel(),
                        $incident->getStatus()->label(),
                    ),
                    // Where the mark goes when it is clicked. Generated by the
                    // caller, which is the only place that knows the router.
                    'href' => $caseFiles[$incident->getReference()] ?? null,
                ],
            ];
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * WHERE EACH MARK LEADS. Generated in the instance method rather than in
     * {@see featuresFor()} because this is the only layer that knows the router,
     * and a builder that generated urls could not be unit-tested without one.
     *
     * @param list<Incident> $incidents
     *
     * @return array<string, string>
     */
    private function caseFiles(AreaOfInterest $area, array $incidents): array
    {
        $urls = [];
        foreach ($incidents as $incident) {
            $urls[$incident->getReference()] = $this->urls->generate(self::CASE_FILE_ROUTE, [
                'uuid' => (string) $area->getUuid(),
                'reference' => $incident->getReference(),
            ]);
        }

        return $urls;
    }

    /**
     * @param list<mixed> $features
     *
     * @return array<string, mixed>
     */
    private static function collection(array $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features];
    }
}
