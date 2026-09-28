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

use Uhifadhi\Contracts\Me\MyCardProviderInterface;

/**
 * THE PERSON'S OWN DASHBOARD'S SIDE OF THE TAG, played by a fixture: what the
 * core's frame collects when it composes `/` for somebody.
 *
 * The kernel wires it on the LITERAL tag name (see TestKernel), for the reason
 * {@see CollectedFileSources} is: a constant follows its own definition, so an
 * assertion built on it would pass through the very rename it is here to catch.
 */
final readonly class CollectedMyCardProviders
{
    /**
     * @param iterable<MyCardProviderInterface> $providers
     */
    public function __construct(
        private iterable $providers,
    ) {
    }

    /** @return list<class-string> */
    public function classes(): array
    {
        $classes = [];
        foreach ($this->providers as $provider) {
            $classes[] = $provider::class;
        }

        return $classes;
    }
}
