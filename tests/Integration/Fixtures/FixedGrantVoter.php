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

namespace Uhifadhi\Incident\Tests\Integration\Fixtures;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Uhifadhi\Bundle\AreaBundle\Access\AreaConcerns;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Incident\Access\IncidentConcerns;

/**
 * Test stand-in for the INSTALLATION's decision about who holds what. The
 * bundle only DECLARES its concerns; which position carries which (concern,
 * verb) pair is the organization's business, written on positions and answered
 * by the core's own {@see \Uhifadhi\Bundle\TeamBundle\Security\GrantVoter}.
 *
 * Here that decision is fixed and DIFFERENT PER ACCOUNT, on purpose — a single
 * blanket "may do everything" stub could never show the splits the module's
 * economics rest on:
 *
 *   - THE REPORTER files and does not move. A report is cheap, a verification
 *     is expensive.
 *   - THE MANAGER does both, and settles the money.
 *   - THE CLERK reads the register and the case file and sees NO MONEY, which
 *     is the whole point of `case-money` being its own sensitive concern: a
 *     fact is withheld without the page it sits on being withheld.
 *
 * THE GROUND'S PAIRS COME FROM {@see AreaConcerns}, never from a literal. The
 * area bundle declares `areas`, and the module's pages are drawn inside the
 * core's frame — the organization dashboard this module contributes a cell to
 * gates `areas.read`. Spelling that out by hand here would be a second copy
 * of somebody else's decision, true only until they changed it.
 *
 * @extends Voter<string, mixed>
 */
final class FixedPermissionVoter extends Voter
{
    /** May file an incident, and may not move one. */
    public const string REPORTER_EMAIL = 'reporter@example.test';

    /** May file AND move, and may settle the money — the supervisor. */
    public const string MANAGER_EMAIL = 'manager@example.test';

    /**
     * Reads everything the manager reads EXCEPT the money, and writes nothing.
     * The account that proves a sensitive concern can be held back on its own.
     */
    public const string CLERK_EMAIL = 'clerk@example.test';

    /** @return list<string> */
    private static function everything(): array
    {
        return [
            // The ground, as ITS owner declares it.
            (string) Grant::of(AreaConcerns::AREAS, Verb::Read),
            // This module's own, as this module declares them.
            (string) Grant::of(IncidentConcerns::INCIDENTS, Verb::Read),
            (string) Grant::of(IncidentConcerns::INCIDENTS, Verb::Record),
            (string) Grant::of(IncidentConcerns::INCIDENTS, Verb::Manage),
            (string) Grant::of(IncidentConcerns::INCIDENTS, Verb::Export),
            (string) Grant::of(IncidentConcerns::VOCABULARY, Verb::Read),
            (string) Grant::of(IncidentConcerns::VOCABULARY, Verb::Configure),
            (string) Grant::of(IncidentConcerns::CASE_FILES, Verb::Read),
            (string) Grant::of(IncidentConcerns::CASE_FILES, Verb::Manage),
            (string) Grant::of(IncidentConcerns::CASE_FILES, Verb::Delete),
            (string) Grant::of(IncidentConcerns::CASE_MONEY, Verb::Read),
            (string) Grant::of(IncidentConcerns::CASE_MONEY, Verb::Manage),
        ];
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, self::everything(), true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return \in_array($attribute, self::heldBy((string) $user->getEmail()), true);
    }

    /**
     * WHAT EACH ACCOUNT HOLDS, and the shape of it is the installation's old
     * behaviour written down rather than a new policy: BEFORE the concerns
     * existed, anybody signed in could read the register and a case file whole,
     * and only the two declared tiers gated anything. So reading is the
     * baseline every account has, and what the named accounts add or lose is
     * exactly what used to be gated — nobody gains a power in this fixture.
     *
     * @return list<string>
     */
    private static function heldBy(string $email): array
    {
        return match ($email) {
            self::MANAGER_EMAIL => self::everything(),
            // The reporter adds filing to the baseline, and nothing else: the
            // kinds and lists editors were never theirs to open.
            self::REPORTER_EMAIL => [...self::reading(), (string) Grant::of(IncidentConcerns::INCIDENTS, Verb::Record)],
            // THE ONE ACCOUNT THAT PROVES A SENSITIVE CONCERN CAN STAND ALONE:
            // the baseline with the money taken off it. They open the same case
            // file everybody else does and the money card is not on it.
            self::CLERK_EMAIL => array_values(array_filter(
                self::reading(),
                static fn (string $pair): bool => !str_starts_with($pair, IncidentConcerns::CASE_MONEY.'.'),
            )),
            default => self::reading(),
        };
    }

    /**
     * THE BASELINE: everything a signed-in person could read before any of
     * this, and not one write.
     *
     * @return list<string>
     */
    private static function reading(): array
    {
        return [
            (string) Grant::of(AreaConcerns::AREAS, Verb::Read),
            (string) Grant::of(IncidentConcerns::INCIDENTS, Verb::Read),
            (string) Grant::of(IncidentConcerns::INCIDENTS, Verb::Export),
            (string) Grant::of(IncidentConcerns::CASE_FILES, Verb::Read),
            (string) Grant::of(IncidentConcerns::CASE_MONEY, Verb::Read),
        ];
    }
}
