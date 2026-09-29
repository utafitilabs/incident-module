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

use Uhifadhi\Incident\Enum\PartyRoleEnum;

/**
 * ONE ROW OF THE INVOLVED PARTIES CARD: a name wearing a role, whether it was
 * recorded as a party or typed into the report form's Parties block.
 */
final readonly class PartyOnRecord
{
    public function __construct(
        public string $name,
        public PartyRoleEnum $role,
        public string $initials,
        public ?string $describedAs = null,
    ) {
    }
}
