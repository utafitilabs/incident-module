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

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Devkit\SeedMonth;
use Uhifadhi\Incident\Entity\TaxonomySubcategory;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Repository\TaxonomySubcategoryRepository;
use Uhifadhi\Incident\Service\TaxonomyAdminService;

/**
 * WORDS FOR AN AREA TO FILE AGAINST — the fixture that replaced "install the
 * shipped taxonomy".
 *
 * The module ships none: an area starts empty and writes its own. So a test that
 * needs something to file an incident against has to write those words first, and
 * it writes them THROUGH {@see TaxonomyAdminService} — the kinds editor's own
 * door — because a fixture that persisted the rows itself could produce a
 * vocabulary the editor cannot, and every test after it would be proving
 * something about a state the product never reaches.
 *
 * THE TABLE IS THE SEED'S ({@see SeedMonth::kinds()}), so the words the suite
 * files against are the words `fixtures:seed` puts in front of a developer, and
 * a test that names `livestock-depredation` names the same row the kinds editor
 * shows.
 */
final readonly class AreaVocabulary
{
    public function __construct(
        private TaxonomyAdminService $admin,
        private TaxonomySubcategoryRepository $subcategories,
    ) {
    }

    /** Write the seed's kinds and sub-categories into one area. */
    public function write(AreaOfInterest $area): void
    {
        foreach (SeedMonth::kinds() as $code => $definition) {
            $kind = $this->admin->createKind($area, $definition['label'], $code);
            $this->admin->setKindLeads($kind, $definition['leads']);

            foreach ($definition['subcategories'] as $subCode => $sub) {
                $subcategory = $this->admin->createSubcategory($kind, $sub['label'], $subCode);
                $direction = null === $sub['money'] ? null : MoneyDirectionEnum::from($sub['money']);
                $blocks = [];
                foreach ($sub['blocks'] as $value) {
                    $block = BehaviorBlockEnum::tryFrom($value);
                    if (null !== $block) {
                        $blocks[] = $block;
                    }
                }
                $this->admin->setBlocks(
                    $subcategory,
                    $blocks,
                    $direction,
                );
                $this->admin->setTermHours($subcategory, $sub['term_hours']);
            }
        }
    }

    /** One of this area's sub-categories by wire-code, or null when it has none. */
    public function subcategory(AreaOfInterest $area, string $code): ?TaxonomySubcategory
    {
        return $this->subcategories->findOneByAreaAndCode($area, $code);
    }
}
