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

namespace Uhifadhi\Incident\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * THE REPORT FORM ASKS WHERE ITS POINT LIES, and this is the seam between the
 * form, its controller and incident_placement. The answer is drawn by the
 * same partials the form renders, so the controller writes no word of it; what
 * can break silently is a name on one side of the seam, so the names are
 * compared as text.
 */
final class ReportPlacementSeamTest extends TestCase
{
    public function testThePointAsksTheAreaWheneverItChanges(): void
    {
        $what = self::read('templates/report/_what.html.twig');
        self::assertSame(2, substr_count($what, "stimulus_action(controller, 'place', 'input')"), 'Both halves of the point ask again.');

        $script = self::read('assets/controllers/incident_report_controller.js');
        self::assertStringContainsString('static values = { placementUrl: String }', $script);
        self::assertStringContainsString('place() {', $script);
        self::assertStringContainsString("url.searchParams.set('lat'", $script);
        self::assertStringContainsString("url.searchParams.set('lng'", $script);
    }

    /** One partial draws the banner both ways, into one slot. */
    public function testTheSlotAndTheNoteAreFilledFromTheEndpointsOwnKeys(): void
    {
        $what = self::read('templates/report/_what.html.twig');
        self::assertStringContainsString("stimulus_target(controller, 'placement')", $what);
        self::assertStringContainsString("stimulus_target(controller, 'where')", $what);
        self::assertStringContainsString('@UhifadhiIncident/report/_placement.html.twig', $what);
        self::assertStringContainsString('@UhifadhiIncident/report/_where.html.twig', $what);

        $endpoint = self::read('src/Controller/IncidentReportController.php');
        self::assertStringContainsString("'banner' => \$this->twig->render('@UhifadhiIncident/report/_placement.html.twig'", $endpoint);
        self::assertStringContainsString("'where' => trim(\$this->twig->render('@UhifadhiIncident/report/_where.html.twig'", $endpoint);

        $script = self::read('assets/controllers/incident_report_controller.js');
        self::assertStringContainsString('answer?.banner', $script);
        self::assertStringContainsString('answer?.where', $script);
        self::assertStringContainsString("'placement', 'where', 'refusedPoint'", $script);
    }

    /** A refused point is owed in the gate by the name the slot gives it. */
    public function testARefusedPointIsOwedByTheSlotsOwnName(): void
    {
        self::assertStringContainsString("data-need=\"{{ placement.pointNeeded }}\" {{ stimulus_target(controller, 'refusedPoint') }}", self::read('templates/report/_placement.html.twig'));
        self::assertStringContainsString('missing.push(this.refusedPointTarget.dataset.need)', self::read('assets/controllers/incident_report_controller.js'));
        self::assertStringContainsString('placement.pointNeeded', self::read('templates/report/_gate.html.twig'));
    }

    private static function read(string $path): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/'.$path);
    }
}
