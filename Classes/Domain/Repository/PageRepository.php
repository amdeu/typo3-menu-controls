<?php

declare(strict_types=1);

namespace Amdeu\MenuControls\Domain\Repository;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;
use Amdeu\MenuControls\Dto\MenuDemand;

/**
 * Repository for 'pages' records.
 *
 * Used by the reference PageMenuController. Implements MenuDemandRepositoryInterface
 * via FindByMenuDemandRepositoryTrait, adding page-specific constraints on top
 * of the generic demand handling.
 *
 * Serves as a concrete example of how to integrate any repository with
 * the menu_controls system.
 */
class PageRepository extends Repository implements MenuDemandRepositoryInterface
{
    use FindByMenuDemandRepositoryTrait;

    public function initializeObject(): void
    {
        $querySettings = GeneralUtility::makeInstance(Typo3QuerySettings::class);
        $querySettings->setRespectStoragePage(false);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * Adds page-specific constraints from demand->additionalSettings.
     *
     * Supported additionalSettings keys:
     *   - types: comma-separated doktype values to include (default: all)
     *   - navHide: when false, excludes pages with nav_hide = 1 (default: true = include all)
     *   - currentPageId: excludes the current page from results when provided
     */
    protected function getAdditionalMenuDemandConstraints(QueryInterface $query, MenuDemand $demand): array
    {
        $constraints = [];
        $settings = $demand->additionalSettings;

        // Restrict to specific page types (doktypes)
        if ($types = $settings['types'] ?? '') {
            $doktypes = GeneralUtility::intExplode(',', $types, true);
            if ($doktypes) {
                $typeConstraints = array_map(
                    fn(int $doktype) => $query->equals('doktype', $doktype),
                    $doktypes
                );
                $constraints[] = count($typeConstraints) === 1
                    ? $typeConstraints[0]
                    : $query->logicalOr(...$typeConstraints);
            }
        }

        // Exclude nav_hide pages when navHide is explicitly false
        if (isset($settings['navHide']) && !$settings['navHide']) {
            $constraints[] = $query->equals('nav_hide', 0);
        }

        // Exclude the current page from results
        if ($settings['excludeCurrentPage'] && $currentPageId = (int)($settings['currentPageId'] ?? 0)) {
            $constraints[] = $query->logicalNot($query->equals('uid', $currentPageId));
        }

        return $constraints;
    }
}
