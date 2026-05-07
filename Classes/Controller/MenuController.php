<?php

declare(strict_types=1);

namespace Amdeu\MenuControls\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Amdeu\MenuControls\Builder\CategoryFilterBuilder;
use Amdeu\MenuControls\Builder\PaginationBuilder;
use Amdeu\MenuControls\Domain\Repository\CategoryRepository;
use Amdeu\MenuControls\Domain\Repository\MenuDemandRepositoryInterface;
use Amdeu\MenuControls\Domain\Repository\PageRepository;
use Amdeu\MenuControls\Dto\CategoryFilter;
use Amdeu\MenuControls\Dto\CategoryItemConfig;
use Amdeu\MenuControls\Dto\MenuDemand;
use Amdeu\MenuControls\Dto\PaginationResult;

/**
 * Base controller providing buildMenuWithControls() for any record type.
 *
 * Extend this class to create your own menu controller — inject your repository,
 * call buildMenuWithControls() from your action, and map records as needed.
 *
 * The pageMenuAction() is a ready-to-use concrete implementation for pages.
 * Override or ignore it if you are building a different kind of menu.
 *
 * @see Resources/Private/Components/ for Fluid component templates
 * @see Resources/Private/FlexForms/PageMenu.xml for FlexForm configuration
 */
class MenuController extends ActionController
{
    protected CategoryFilterBuilder $categoryFilterBuilder;
    protected PaginationBuilder     $paginationBuilder;
    protected CategoryRepository    $categoryRepository;
    protected PageRepository        $pageRepository;

    public function injectCategoryFilterBuilder(CategoryFilterBuilder $builder): void
    {
        $this->categoryFilterBuilder = $builder;
    }

    public function injectPaginationBuilder(PaginationBuilder $builder): void
    {
        $this->paginationBuilder = $builder;
    }

    public function injectCategoryRepository(CategoryRepository $repository): void
    {
        $this->categoryRepository = $repository;
    }

    public function injectPageRepository(PageRepository $repository): void
    {
        $this->pageRepository = $repository;
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Concrete page menu action — ready to use out of the box.
     * Override to add additional filter groups or use a different repository.
     */
    public function pageMenuAction(): ResponseInterface
    {
        $variables = $this->buildDemandMenuWithControls($this->pageRepository, 'pageMenu');
        $variables['records'] = $this->pageRepository->mapToRecords($variables['records']);
        $this->view->assignMultiple($variables);
        return $this->htmlResponse();
    }

    // -------------------------------------------------------------------------
    // Core
    // -------------------------------------------------------------------------

    /**
     * Builds the menu variables for any repository implementing MenuDemandRepositoryInterface.
     *
     * Returns an array ready to be assigned to the view:
     *   - records:         raw DB rows for the current page (map to models/Records as needed)
     *   - pagination:      Pagination DTO (only present when pagination is enabled)
     *   - categoryFilters: keyed array of CategoryFilter DTOs, one per filter group
     *                      (only present when at least one filter group is enabled)
     *
     * Single filter (default):
     *   $this->buildDemandMenuWithControls($this->newsRepository, 'newsMenu');
     *   // produces: categoryFilters['main']
     *
     * Multiple concurrent filters:
     *   $this->buildDemandMenuWithControls($this->newsRepository, 'newsMenu', [
     *       'topics'  => $this->getFilterConfig(),
     *       'regions' => $this->getRegionFilterConfig(),
     *   ]);
     *   // produces: categoryFilters['topics'], categoryFilters['regions']
     */
    protected function buildDemandMenuWithControls(
        MenuDemandRepositoryInterface $repository,
        string                        $actionName,
        array                         $filterConfigs = null,
    ): array {
        $filterConfigs ??= ['main' => $this->getFilterConfig()];

        $demand      = $this->buildDemand(respectActiveCategories: true, categoryGroupKeys: array_keys($filterConfigs));
        $allRows     = $repository->findByMenuDemand($demand, true);
        $currentPage = (int)($this->request->getArguments()['page'] ?? 1);
        $variables   = [];

        $categoryFilters = [];
        foreach ($filterConfigs as $groupKey => $config) {
            if ($config['enabled'] ?? false) {
                $categoryFilters[$groupKey] = $this->buildCategoryFilter($actionName, $config, $groupKey, $demand, $repository);
            }
        }
        if ($categoryFilters) {
            $variables['categoryFilters'] = $categoryFilters;
        }

        if ($this->getPaginationConfig()['enabled'] ?? false) {
            $result = $this->buildPagination($actionName, $allRows, $currentPage);
            $variables['records']    = $result->paginatedItems;
            $variables['pagination'] = $result->pagination;
        } else {
            $variables['records'] = $allRows;
        }

        return $variables;
    }

    // -------------------------------------------------------------------------
    // Builder methods — override to customise
    // -------------------------------------------------------------------------

    /**
     * Builds a CategoryFilter for a single filter group.
     *
	 * @param string                           $actionName The action name for URL generation
     * @param array                            $config     Filter config for this group
     * @param string                           $categoryGroupKey  The group key within categoryGroups
     * @param MenuDemand|null                  $demand     Base demand for potential checking (optional)
     * @param MenuDemandRepositoryInterface|null $repository Repository for potential checking (optional)
     */
    protected function buildCategoryFilter(
        string                        $actionName,
        array                         $config,
        string                        $categoryGroupKey = 'main',
        ?MenuDemand                   $demand = null,
        ?MenuDemandRepositoryInterface $repository = null,
    ): CategoryFilter {
        return $this->categoryFilterBuilder->build(
            configs: $this->buildCategoryItemConfigs($config),
            urlBuilder: fn(string $uids) => $this->buildUrl($actionName, [
                'demand' => ['categoryGroups' => [$categoryGroupKey => ['uids' => $uids ?: null]]],
                'page'   => null,
            ]),
            fragmentUrlBuilder: fn(string $uids) => $this->buildFragmentUrl($actionName, [
                'demand' => ['categoryGroups' => [$categoryGroupKey => ['uids' => $uids ?: null]]],
                'page'   => null,
            ]),
            activeUids: $this->getActiveCategoryUids($categoryGroupKey),
            multiSelect: (bool)($config['multiSelect'] ?? true),
            checkPotential: (bool)($config['checkPotential'] ?? false),
            potentialRepository: $repository,
            potentialDemand: $demand,
            potentialGroupKey: $categoryGroupKey,
            resetLabel: $config['resetLabel'] ?? 'All',
        );
    }

    /**
     * Builds the PaginationResult for the given records and current page.
     */
    protected function buildPagination(
        string $actionName,
        array  $allRows,
        int    $currentPage,
    ): PaginationResult {
        $config = $this->getPaginationConfig();
        return $this->paginationBuilder->build(
            records: $allRows,
            urlBuilder: fn(int $page) => $this->buildUrl($actionName, ['page' => $page > 1 ? $page : null]),
            fragmentUrlBuilder: fn(int $page) => $this->buildFragmentUrl($actionName, ['page' => $page > 1 ? $page : null]),
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
     * Builds CategoryItemConfig objects from a filter config array.
     *
     * @param ?array $config Filter config — defaults to $this->getFilterConfig()
     * @return array<int, CategoryItemConfig>
     */
    protected function buildCategoryItemConfigs(array $config = null): array
    {
        $config ??= $this->getFilterConfig();
        $categoryUids     = GeneralUtility::intExplode(',', $config['categories'] ?? '', true);
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
     * When $respectActiveCategories is true, active UIDs from the request are
     * merged for each group key so the repository query is correctly filtered.
     *
     * @param array $categoryGroupKeys Group keys to read active UIDs for
     */
    protected function buildDemand(
        bool  $respectActiveCategories = false,
        array $categoryGroupKeys = ['main'],
    ): MenuDemand {
        $settings = $this->settings['demand'] ?? [];
        if ($respectActiveCategories) {
            foreach ($categoryGroupKeys as $key) {
                $settings['categoryGroups'][$key]['uids'] = $this->getActiveCategoryUids($key);
            }
        }
        $settings['additionalSettings']['currentPageId'] = $this->request->getAttribute('routing')->getPageId();
        return MenuDemand::createFromArray($settings);
    }

	/**
	 * Builds an absolute URL for the given action and argument overrides.
	 * Merges current request arguments with overrides
	 */
    protected function buildUrl(string $actionName, array $overrides): string
    {
        $args = $this->request->getArguments();
        return $this->uriBuilder
            ->reset()
            ->setTargetPageUid($this->request->getAttribute('routing')->getPageId())
            ->setCreateAbsoluteUri(true)
            ->uriFor($actionName, $this->removeNullValues(array_replace_recursive($args, $overrides)));
    }

	/**
	 * Builds a fragment/AJAX URL for the given action and overrides.
	 * Returns an empty string by default — override to enable fragment-based rendering.
	 * The return value is passed as fragmentUrl on all filter and pagination items,
	 * enabling AJAX-driven partial page updates.
	 *
	 * Example override:
	 *   protected function buildFragmentUrl(string $actionName, array $overrides): string
	 *   {
	 *       return $this->uriBuilder
	 *           ->reset()
	 *           ->setTargetPageUid($this->request->getAttribute('routing')->getPageId())
	 *           ->setTargetPageType(1234567)
	 *           ->uriFor($actionName, $this->removeNullValues(array_replace_recursive(
	 *               $this->request->getArguments(), $overrides
	 *           )));
	 *   }
	 */
	protected function buildFragmentUrl(string $actionName, array $overrides): string
	{
		return '';
	}

    /**
     * Recursively removes null values from an array, also pruning empty sub-arrays.
     * Used by buildUrl() to ensure absent arguments don't appear in generated URLs.
     */
    protected function removeNullValues(array $array): array
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->removeNullValues($value);
                if (empty($array[$key])) {
                    unset($array[$key]);
                }
            } elseif ($value === null) {
                unset($array[$key]);
            }
        }
        return $array;
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
        return $this->request->getArguments()['demand']['categoryGroups'][$groupKey]['uids'] ?? '';
    }
}
