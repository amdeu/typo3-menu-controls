<?php

declare(strict_types=1);

namespace Amdeu\MenuControls\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Amdeu\MenuControls\Builder\CategoryFilterBuilder;
use Amdeu\MenuControls\Builder\PaginationBuilder;
use Amdeu\MenuControls\Domain\Repository\PageRepository;
use Amdeu\MenuControls\Dto\CategoryFilter;
use Amdeu\MenuControls\Dto\CategoryItemConfig;
use Amdeu\MenuControls\Dto\MenuDemand;
use Amdeu\MenuControls\Dto\PaginationResult;

/**
 * Reference controller for a page-based menu plugin.
 *
 * Use as-is or extend as a base for your own controller. Override the
 * protected helper methods to customise demand building, URL generation,
 * or builder configuration.
 *
 * @see PageRepository
 * @see Resources/Private/Components/ for Fluid component templates
 */
class PageMenuController extends ActionController
{
    public function __construct(
        protected readonly PageRepository        $pageRepository,
        protected readonly CategoryFilterBuilder $categoryFilterBuilder,
        protected readonly PaginationBuilder     $paginationBuilder,
    ) {}

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    public function menuAction(): ResponseInterface
    {
        $demand      = $this->buildDemand(respectActiveCategories: true);
        $pageRows    = $this->pageRepository->findByMenuDemand($demand, true);
        $currentPage = (int)($this->request->getArgument('page') ?? 1);

        if ($this->getFilterConfig()['enabled'] ?? false) {
            $this->view->assign('categoryFilter', $this->buildCategoryFilter($demand));
        }

        if ($this->getPaginationConfig()['enabled'] ?? false) {
            $result = $this->buildPagination($pageRows, $currentPage);
            $this->view->assignMultiple([
                'pages'      => $this->pageRepository->mapToRecords($result->paginatedItems),
                'pagination' => $result->pagination,
            ]);
        } else {
            $this->view->assign('pages', $this->pageRepository->mapToRecords($pageRows));
        }

        return $this->htmlResponse();
    }

    // -------------------------------------------------------------------------
    // Builder methods — override to customise
    // -------------------------------------------------------------------------

    /**
     * Builds the CategoryFilter for the menu action.
     * Override to add tree categories, change multiSelect behaviour, etc.
     */
    protected function buildCategoryFilter(MenuDemand $demand, string $groupKey = 'main'): CategoryFilter
    {
        $config = $this->getFilterConfig();
        return $this->categoryFilterBuilder->build(
            configs: $this->buildCategoryItemConfigs(),
			urlBuilder: fn(string $uids) => $this->uriBuilder
				->reset()
				->setCreateAbsoluteUri(true)
				->uriFor('menu', array_replace_recursive(
					$this->request->getArguments(),
					[
						'demand' => ['categoryGroups' => [$groupKey => ['uids' => $uids ?: null]]],
						'page'   => null,
					]
				)),
            activeUids: $this->getActiveCategoryUids($groupKey),
            multiSelect: (bool)($config['multiSelect'] ?? true),
            checkPotential: (bool)($config['checkPotential'] ?? false),
            potentialRepository: $this->pageRepository,
            potentialDemand: $demand,
            potentialGroupKey: $groupKey,
			resetLabel: $config['resetLabel'] ?? 'All',
		);
    }

    /**
     * Builds the PaginationResult for the menu action.
     * Override to adjust items per page, maximum links, etc.
     */
    protected function buildPagination(array $pageRows, int $currentPage): PaginationResult
    {
        $config = $this->getPaginationConfig();
        return $this->paginationBuilder->build(
            records: $pageRows,
			urlBuilder: fn(int $page) => $this->uriBuilder
				->reset()
				->setCreateAbsoluteUri(true)
				->uriFor('menu', array_replace_recursive(
					$this->request->getArguments(),
					['page' => $page > 1 ? $page : null]
				)),
            currentPage: $currentPage,
            itemsPerPage: (int)($config['itemsPerPage'] ?? 12),
            maximumLinks: (int)($config['maximumLinks'] ?? 3),
            addHeadLinks: true,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Builds CategoryItemConfig objects from FlexForm settings.
     * Tree parents use disabledLevels:1 — the parent is a non-selectable header,
     * its children are the selectable filter items.
     *
     * @return array<int, CategoryItemConfig>
     */
    protected function buildCategoryItemConfigs(): array
    {
        $config = $this->getFilterConfig();
        $categoryUids = GeneralUtility::intExplode(',', $config['categories'] ?? '', true);
        $treeCategoryUids = GeneralUtility::intExplode(',', $config['treeCategories'] ?? '', true);

        return array_merge(
            array_map(fn(int $uid) => new CategoryItemConfig(uid: $uid), $categoryUids),
            array_map(fn(int $uid) => new CategoryItemConfig(
                uid: $uid,
                depth: $config['treeDepth'] ?? 1,
                disabledLevels: $config['treeDisabledLevels'] ?? 1,
                siblingExclusiveLevels: $config['treeSiblingExclusiveLevels'] ?? 0,
                buildTreeBelowEnabledInactive: (bool)($config['buildTreeBelowEnabledInactive'] ?? false),
            ), $treeCategoryUids),
        );
    }

    /**
     * Builds a MenuDemand from FlexForm settings.
     * When $respectActiveCategories is true, active category UIDs from the
     * request are merged into the demand so the repository query is filtered.
     */
    protected function buildDemand(bool $respectActiveCategories = false, string $categoryGroupKey = 'main'): MenuDemand
    {
        $settings = $this->settings['demand'] ?? [];
        if ($respectActiveCategories) {
            $settings['categoryGroups'][$categoryGroupKey]['uids'] = $this->getActiveCategoryUids($categoryGroupKey);
        }
        $settings['additionalSettings']['currentPageId'] = $this->request->getAttribute('routing')->getPageId();
        return MenuDemand::createFromArray($settings);
    }

    protected function getPaginationConfig(): array
    {
        return $this->settings['pagination'] ?? [];
    }

    protected function getFilterConfig(): array
    {
        return $this->settings['categoryFilter'] ?? [];
    }

    /**
     * Active category UIDs for the given group from the request URL parameter.
     */
    protected function getActiveCategoryUids(string $groupKey = 'main'): string
    {
        return $this->request->getArgument('demand')['categoryGroups'][$groupKey]['uids'] ?? '';
    }
}
