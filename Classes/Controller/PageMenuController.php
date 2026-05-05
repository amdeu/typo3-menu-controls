<?php

declare(strict_types=1);

namespace Amdeu\MenuControls\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Amdeu\MenuControls\Builder\CategoryFilterBuilder;
use Amdeu\MenuControls\Builder\PaginationBuilder;
use Amdeu\MenuControls\Domain\Repository\CategoryRepository;
use Amdeu\MenuControls\Domain\Repository\PageRepository;
use Amdeu\MenuControls\Dto\CategoryItemConfig;
use Amdeu\MenuControls\Dto\MenuDemand;

/**
 * Reference controller for a page-based menu plugin.
 *
 * Three actions of increasing complexity — use as-is or extend as a base
 * for your own controller. Override the protected helper methods to customise
 * demand building, URL generation, or builder configuration without touching
 * the action logic.
 *
 * @see PageRepository
 * @see Resources/Private/Components/ for Fluid component templates
 */
class PageMenuController extends ActionController
{
    public function __construct(
        protected readonly PageRepository     $pageRepository,
        protected readonly CategoryRepository $categoryRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Simple list — no pagination, no category filter.
     */
    public function listAction(): ResponseInterface
    {
        $pageRows = $this->pageRepository->findByMenuDemand($this->buildDemand(), true);
        $this->view->assign('pages', $this->pageRepository->mapToRecords($pageRows));
        return $this->htmlResponse();
    }

    /**
     * Paginated list. Pagination can be disabled via settings.pagination.enabled.
     */
    public function paginatedListAction(): ResponseInterface
    {
        $currentPage = (int)($this->request->getArgument('page') ?? 1);
        $pageRows    = $this->pageRepository->findByMenuDemand($this->buildDemand(), true);

        if ($this->getPaginationConfig()['enabled']) {
            $paginationBuilder = $this->createPaginationBuilder('paginatedList', $pageRows, $currentPage);
            $this->view->assignMultiple([
                'pages'      => $this->pageRepository->mapToRecords($paginationBuilder->getPaginatedItems()),
                'pagination' => $paginationBuilder->build(),
            ]);
        } else {
            $this->view->assign('pages', $this->pageRepository->mapToRecords($pageRows));
        }

        return $this->htmlResponse();
    }

    /**
     * Filtered and paginated list. Both filter and pagination can be toggled
     * via settings.categoryFilter.enabled and settings.pagination.enabled.
     *
     * URL builders use array_replace_recursive() to preserve full request state
     * when updating a single parameter — this ensures concurrent filters don't
     * overwrite each other, and that changing the filter resets the page to 1.
     */
    public function filteredListAction(): ResponseInterface
    {
        $currentPage = (int)($this->request->getArgument('page') ?? 1);
        $demand      = $this->buildDemand(respectActiveCategories: true);
        $pageRows    = $this->pageRepository->findByMenuDemand($demand, true);

        if ($this->getFilterConfig()['enabled']) {
            $this->view->assign(
                'categoryFilter',
                $this->createCategoryFilterBuilder('filteredList', $demand)
                    ->build($this->buildCategoryItemConfigs())
            );
        }

        if ($this->getPaginationConfig()['enabled']) {
            $paginationBuilder = $this->createPaginationBuilder('filteredList', $pageRows, $currentPage);
            $this->view->assignMultiple([
                'pages'      => $this->pageRepository->mapToRecords($paginationBuilder->getPaginatedItems()),
                'pagination' => $paginationBuilder->build(),
            ]);
        } else {
            $this->view->assign('pages', $this->pageRepository->mapToRecords($pageRows));
        }

        return $this->htmlResponse();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Creates a PaginationBuilder for the given action.
     */
    protected function createPaginationBuilder(string $actionName, array $pageRows, int $currentPage): PaginationBuilder
    {
        $config = $this->getPaginationConfig();
        return (new PaginationBuilder($pageRows, $currentPage))
            ->withItemsPerPage((int)($config['itemsPerPage'] ?? 12))
            ->withMaximumLinks((int)($config['maximumLinks'] ?? 3))
            ->withUrlBuilder(fn(int $page) => $this->uriBuilder
                ->reset()
                ->setCreateAbsoluteUri(true)
                ->uriFor($actionName, array_replace_recursive(
                    $this->request->getArguments(),
                    ['page' => $page > 1 ? $page : null]
                ))
            )
            ->addPaginationLinksToHead();
    }

    /**
     * Creates a CategoryFilterBuilder for the given action and demand.
     */
    protected function createCategoryFilterBuilder(string $actionName, MenuDemand $demand, string $groupKey = 'main'): CategoryFilterBuilder
    {
        $config = $this->getFilterConfig();
        return (new CategoryFilterBuilder($this->categoryRepository))
            ->withActiveUids($this->getActiveCategoryUids($groupKey))
            ->withMultiSelect((bool)($config['multiSelect'] ?? true))
            ->withCheckPotential(
                (bool)($config['checkPotential'] ?? false),
                $this->pageRepository,
                $demand,
                $groupKey
            )
            ->withUrlBuilder(fn(string $uids) => $this->uriBuilder
                ->reset()
                ->setCreateAbsoluteUri(true)
                ->uriFor($actionName, array_replace_recursive(
                    $this->request->getArguments(),
                    [
                        'demand' => ['categoryGroups' => [$groupKey => ['uids' => $uids ?: null]]],
                        'page'   => null,
                    ]
                ))
            );
    }

    /**
     * Builds CategoryItemConfig objects from FlexForm settings.
     * Flat categories and tree parent categories are merged in order.
     * Tree parents use depth:1, disabledLevels:1 — the parent is a non-selectable
     * header, its children are the selectable filter items.
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
     * When $respectActiveCategories is true, the active category UIDs from the
     * request URL are merged into the demand so the repository query is filtered.
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
     * Reads the active category UIDs for a given group from the request URL parameter.
     */
    protected function getActiveCategoryUids(string $groupKey = 'main'): string
    {
        return $this->request->getArgument('demand')['categoryGroups'][$groupKey]['uids'] ?? '';
    }
}
