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

namespace Uhifadhi\Incident\Me;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Contracts\Me\MyCard;
use Uhifadhi\Contracts\Me\MyCardProviderInterface;
use Uhifadhi\Incident\Access\IncidentConcerns;
use Uhifadhi\Incident\Access\IncidentDoors;
use Uhifadhi\Incident\Model\IncidentMyReading;
use Uhifadhi\Incident\Repository\IncidentRepository;

/**
 * WHAT INCIDENTS SHOWS A PERSON ABOUT THEMSELVES on their own dashboard (#19,
 * option A ruled 28 Sep 2026): ME·12, "Incidents I reported", in the row at
 * the foot — after the patrol module's observations (10), before the roster's
 * leave (25) and the team's phone (30).
 *
 * "I REPORTED" IS {@see \Uhifadhi\Incident\Entity\Incident::$reportedBy} —
 * the account a report is filed under, which the report form sets to the
 * person signed in when they file ({@see \Uhifadhi\Incident\Service\IncidentReportService::file()}).
 * An incident nobody filed by hand (seeded, imported) has no reporter and is
 * on nobody's card.
 *
 * THE ROWS ARE THE PERSON'S OWN, from every area, shown because they are
 * theirs and not because a grant says so — the same rule the ground's own
 * cards follow. A row is not a link: the case file is gated on the area's
 * `incidents.read`, and a row that answered 403 would be a lie about the
 * card.
 *
 * THE DOOR, "Report one", IS DRAWN ONLY FOR SOMEBODY WHO MAY FILE, asked
 * through {@see IncidentDoors} with the same pair the report route enforces
 * (`incidents.record`, subject: the area) — so it fails closed with nobody
 * signed in or no security at all. It leads to the report form of the area
 * the person last reported in when they may still file there, and otherwise
 * to the first area in the register they may file in: a person usually
 * reports on the ground they work.
 *
 * Wired by hand in config/services.php and tagged with the contract's own
 * constant, because a reusable bundle is not autoconfigured:
 * "Services should not use autowiring or autoconfiguration. Instead, all
 * services should be defined explicitly." —
 * https://symfony.com/doc/current/bundles/best_practices.html#services
 */
final readonly class IncidentMyCards implements MyCardProviderInterface
{
    public function __construct(
        private Environment $twig,
        private IncidentRepository $incidents,
        private AreaOfInterestRepository $areas,
        private IncidentDoors $doors,
        private UrlGeneratorInterface $router,
    ) {
    }

    public function cardsFor(string $personUuid, \DateTimeImmutable $now): array
    {
        $reported = $this->incidents->findReportedByPerson($personUuid, IncidentMyReading::LATEST);
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);

        $reading = new IncidentMyReading(
            $now,
            $reported,
            $this->incidents->countReportedByPersonBetween($personUuid, $monthStart, $monthStart->modify('+1 month')),
            $this->reportUrl(isset($reported[0]) ? $reported[0]->getArea() : null),
        );

        return [
            new MyCard(MyCard::ROW, 20, $this->twig->render('@UhifadhiIncident/me/_reported_card.html.twig', [
                'reading' => $reading,
            ])),
        ];
    }

    /** Where "Report one" leads, or null where the signed-in person may file nowhere. */
    private function reportUrl(?AreaOfInterest $lastReportedIn): ?string
    {
        $candidates = null === $lastReportedIn ? [] : [$lastReportedIn];
        foreach ([...$candidates, ...$this->areas->findAllOrdered()] as $area) {
            if ($this->doors->opens(IncidentConcerns::INCIDENTS, Verb::Record, $area)) {
                return $this->router->generate('incident_new', ['uuid' => $area->getUuidString()]);
            }
        }

        return null;
    }
}
