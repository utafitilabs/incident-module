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

namespace Uhifadhi\Incident\Access;

use Uhifadhi\Contracts\Access\Concern;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Incident\Module\IncidentModuleProvider;

/**
 * WHAT THERE IS TO HAVE A PERMISSION ABOUT IN THIS MODULE - four things, and
 * the reason there are four rather than one is the whole of this file.
 *
 * THE RECORD ITSELF is one concern. Reading the register, filing a report,
 * moving a case through its workflow and taking the month away as a file are
 * four verbs on one thing, and an organization that wants a warden who reads
 * and a supervisor who moves has them without anybody inventing a role.
 *
 * THE WORDS AN AREA FILES AGAINST are a second, because setting up a
 * vocabulary is a different job from using it. Somebody who works incidents
 * all day is not thereby the person who decides what kinds of incident exist,
 * and the one who decides is often not in the field at all.
 *
 * AND THEN THE TWO THAT CAN BE WITHHELD FROM SOMEBODY WHO READS THE REST.
 * This is the point of declaring them separately rather than folding them
 * into the record they sit on:
 *
 *   - THE CASE FILE - the narrative as it was taken, the parties (a suspect,
 *     a claimant, an informant, a witness), and the evidence attached to it.
 *     These are facts about PEOPLE, and they are the facts an incident record
 *     is most dangerous to leak: an informant named on a poaching case is
 *     endangered by the reading, not by the filing.
 *   - THE MONEY - a fine, a claim, an assessment and what was actually paid.
 *     Money attracts a different kind of interest from everything else on the
 *     page, and an organization routinely wants the figures held by the few
 *     who settle them while the case stays readable by everybody working it.
 *
 * BOTH ARE SENSITIVE, and both are read SEPARATELY from the page they sit on.
 * That is the design: the incident record opens on `incidents.read` and its
 * case-file and money cards are drawn only where their own concern is held,
 * so withholding a fact never means withholding the screen.
 *
 * WHOEVER ENFORCES A CONCERN DECLARES IT, which is why these are here and not
 * in the core. They arrive with the module and they leave with it.
 *
 * NO "OWN" SCOPE ANYWHERE. It would mean "the incidents I filed", and the
 * module's charter forbids anything that lets one reader see a subset of a
 * register another reader sees in full - the whole point of filing an
 * incident once is that every department that needs it reads the same row.
 *
 * HOW A PAIR DECLARED HERE IS ENFORCED. Every route of this module states its
 * pair with #[IsGranted(<key>.<verb>, subject: 'area')], and the attribute is
 * honoured by a listener that ships in symfony/security-http, on the
 * controller-arguments event, resolving `subject` by ARGUMENT NAME and asking
 * the authorization checker with the area the route already resolved. So a
 * kernel without SecurityBundle honours none of them - which is why no screen
 * that WRITES is registered where SecurityBundle is absent
 * (UhifadhiIncidentBundle::loadExtension()).
 *
 * @see https://symfony.com/doc/current/security.html#access-control-in-controllers
 * @see vendor/symfony/security-http/EventListener/IsGrantedAttributeListener.php
 */
final readonly class IncidentConcerns implements ConcernSourceInterface
{
    /** The keys, spelt once, so a gate, a door and a test cannot disagree. */
    public const string INCIDENTS = 'incidents';
    public const string VOCABULARY = 'incident-vocabulary';
    public const string CASE_FILES = 'case-files';
    public const string CASE_MONEY = 'case-money';

    public function declaredBy(): string
    {
        return 'Incidents';
    }

    public function concerns(): iterable
    {
        /*
         * ORGANIZATION OR AREA, AND NOT DEPARTMENT. An incident happens on
         * ground, and every screen this module ships is under an area. The
         * department dimension is asked anyway, and asked better: these
         * concerns name their module, so the voter's third question - is the
         * person placed in a department that runs incidents - is answered
         * from the placement without anybody scoping a grant by hand.
         */
        $ground = [ScopeKind::Organization, ScopeKind::Area];

        yield new Concern(
            key: self::INCIDENTS,
            label: 'Incidents',
            description: 'What happened in an area: the register, filing a report, moving a case through verification, response and closure, and taking the month away as a file.',
            // NO DELETE. A VERB IS DECLARED WHERE SOMETHING ENFORCES IT, and
            // nothing here destroys an incident: a case is resolved and filed,
            // and a correction is a new event rather than an erasure. A
            // declared power nothing enforces is a box an administrator can
            // tick that changes nothing, which is worse than a missing one.
            // It arrives with the first thing that deletes a record.
            verbs: [Verb::Read, Verb::Record, Verb::Manage, Verb::Export],
            scopeKinds: $ground,
            moduleSlug: IncidentModuleProvider::SLUG,
        );

        yield new Concern(
            key: self::VOCABULARY,
            label: 'Incident kinds and lists',
            description: 'How this area is set up to file: the kinds of incident and their sub-categories, the words its questions offer, and what it counts money in.',
            verbs: [Verb::Read, Verb::Configure],
            scopeKinds: $ground,
            moduleSlug: IncidentModuleProvider::SLUG,
        );

        yield new Concern(
            key: self::CASE_FILES,
            label: 'Case files',
            description: 'The sensitive half of a record: the narrative as it was taken, the suspects, claimants, informants and witnesses named on it, and the evidence attached to it.',
            // DELETE IS ITS OWN VERB because taking evidence off a case is at
            // least as serious as putting it on, and the file source already
            // refuses it while the case is open. An organization that lets a
            // clerk attach photographs need not let the same clerk remove one.
            verbs: [Verb::Read, Verb::Manage, Verb::Delete],
            scopeKinds: $ground,
            sensitive: true,
            moduleSlug: IncidentModuleProvider::SLUG,
        );

        yield new Concern(
            key: self::CASE_MONEY,
            label: 'Case money',
            description: 'The money on a case: the fine or the claim, what was assessed and approved, what has been paid, and waiving the rest with a reason.',
            verbs: [Verb::Read, Verb::Manage],
            scopeKinds: $ground,
            sensitive: true,
            moduleSlug: IncidentModuleProvider::SLUG,
        );
    }
}
