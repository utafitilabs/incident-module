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

namespace Uhifadhi\Incident\Model;

use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentParty;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Enum\PartyRoleEnum;

/**
 * EVERYBODY ON THE CASE, WHEREVER THEY WERE WRITTEN DOWN.
 *
 * The reporter is a party record from the moment the report is filed, and the
 * case file adds parties as records. A claimant, a suspect or a witness typed
 * into the report form's Parties block is kept as that block's rows — the
 * answers the form asked — and is shown under the block as such. The Involved
 * parties card is the case's answer to "who is on this", so it reads both, and
 * never copies one into the other: a second copy would drift the first time an
 * answer was edited.
 *
 * ONCE EACH. A row that names a party already on the record in the same role
 * (the name compared without case or surrounding space) is that party.
 */
final class PartiesOnRecord
{
    /** @return list<PartyOnRecord> */
    public static function of(Incident $incident): array
    {
        $parties = [];
        $seen = [];
        foreach ($incident->getParties() as $party) {
            $parties[] = new PartyOnRecord($party->getName(), $party->getRole(), $party->initials(), $party->getDescribedAs());
            $seen[self::identity($party->getRole(), $party->getName())] = true;
        }

        $answers = new BlockAnswers($incident->getBlockAnswers());
        foreach ($answers->rows(BehaviorBlockEnum::Parties) as $row) {
            $role = PartyRoleEnum::tryFrom($row['role'] ?? '');
            $name = trim($row['name'] ?? '');
            if (null === $role || '' === $name || isset($seen[self::identity($role, $name)])) {
                continue;
            }

            $household = trim($row['household'] ?? '');
            $parties[] = new PartyOnRecord($name, $role, IncidentParty::initialsOf($role, $name), '' === $household ? null : $household);
            $seen[self::identity($role, $name)] = true;
        }

        return $parties;
    }

    private static function identity(PartyRoleEnum $role, string $name): string
    {
        return $role->value.'|'.mb_strtolower(trim($name));
    }
}
