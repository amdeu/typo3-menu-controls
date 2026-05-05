<?php

declare(strict_types=1);

namespace Amdeu\MenuControls\Builder;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Amdeu\MenuControls\Domain\Repository\CategoryRepository;
use Amdeu\MenuControls\Domain\Repository\MenuDemandRepositoryInterface;
use Amdeu\MenuControls\Dto\CategoryFilter;
use Amdeu\MenuControls\Dto\CategoryFilterItem;
use Amdeu\MenuControls\Dto\CategoryItemConfig;
use Amdeu\MenuControls\Dto\MenuDemand;

/**
 * Produces a CategoryFilter DTO from a list of CategoryItemConfig objects.
 *
 * Fetches root category records in one query, then recurses into children
 * as configured. Configs are processed in order — flat items and tree roots
 * can be freely interleaved.
 *
 * Usage:
 *
 *   $filter = $this->categoryFilterBuilder->build(
 *       configs: [
 *           new CategoryItemConfig(uid: 5),
 *           new CategoryItemConfig(uid: 12, depth: 2, disabledLevels: 1),
 *       ],
 *       urlBuilder: fn(string $uids) => $this->uriBuilder->uriFor('menu', [
 *           'demand' => ['categoryGroups' => ['main' => ['uids' => $uids ?: null]]],
 *           'page'   => null,
 *       ]),
 *       activeUids: $request->getArgument('demand')['categoryGroups']['main']['uids'] ?? '',
 *   );
 */
#[Autoconfigure(public: true)]
class CategoryFilterBuilder
{
    public function __construct(
        protected CategoryRepository $categoryRepository,
    ) {}

    /**
     * @param CategoryItemConfig[]                $configs
     * @param \Closure(string $uids): string      $urlBuilder            Receives new active UIDs, returns absolute URL
     * @param \Closure(string $uids): string|null $fragmentUrlBuilder    Optional AJAX URL builder
     * @param string                              $activeUids            Comma-separated active UIDs from the request
     * @param bool                                $multiSelect           When false, selecting any item replaces the full selection
     * @param bool                                $checkPotential        When true, each item receives a potentialCount
     * @param MenuDemandRepositoryInterface|null  $potentialRepository   Repository for potential count queries
     * @param MenuDemand|null                     $potentialDemand       Base demand cloned per item for potential queries
     * @param string                              $potentialGroupKey     categoryGroups key to override for potential queries
     * @param string                              $labelField            Category field to use as display label
     * @param string                              $labelFieldFallback    Fallback label field
     * @param string                              $resetLabel            Label for the reset item; empty string suppresses it
     */
    public function build(
        array                          $configs,
        \Closure                       $urlBuilder,
        ?\Closure                      $fragmentUrlBuilder = null,
        string                         $activeUids = '',
        bool                           $multiSelect = true,
        bool                           $checkPotential = false,
        ?MenuDemandRepositoryInterface $potentialRepository = null,
        ?MenuDemand                    $potentialDemand = null,
        string                         $potentialGroupKey = '0',
        string                         $labelField = 'title',
        string                         $labelFieldFallback = 'title',
        string                         $resetLabel = 'Reset',
    ): CategoryFilter {

        $context = new CategoryFilterBuildContext(
            urlBuilder: $urlBuilder,
            fragmentUrlBuilder: $fragmentUrlBuilder,
            activeUids: $activeUids,
            multiSelect: $multiSelect,
            checkPotential: $checkPotential,
            potentialRepository: $potentialRepository,
            potentialDemand: $potentialDemand,
            potentialGroupKey: $potentialGroupKey,
            labelField: $labelField,
            labelFieldFallback: $labelFieldFallback,
        );

        $uids = implode(',', array_map(fn(CategoryItemConfig $c) => $c->uid, $configs));
        $rootRecords = $this->categoryRepository->findByUidList($uids, true);
        $rootRecordsByUid = array_column($rootRecords, null, 'uid');
        $rootSiblings = array_values($rootRecordsByUid);

        $filterItems = [];
        foreach ($configs as $config) {
            $category = $rootRecordsByUid[$config->uid] ?? null;
            if ($category === null) continue;

            $filterItems[] = $config->depth > 0
                ? $this->buildTree(
                    $category,
                    $rootSiblings,
                    $config->depth,
                    $config->disabledLevels,
                    $config->siblingExclusiveLevels,
                    $config->buildTreeBelowEnabledInactive,
                    $context,
                )
                : $this->buildFilterItem(
                    $category,
                    $context,
                    disabled: false,
                    siblingExclusive: !$multiSelect || $config->siblingExclusiveLevels !== 0,
                    activeSiblings: array_values(array_filter(
                        array_column($rootSiblings, 'uid'),
                        fn($uid) => $uid !== $config->uid && $this->isActive($uid, $activeUids)
                    )),
                );
        }

        $resetItem = null;
        if ($resetLabel !== '' && $activeUids !== '') {
            $resetItem = new CategoryFilterItem(
                label: $resetLabel,
                url: $urlBuilder(''),
                fragmentUrl: $fragmentUrlBuilder ? $fragmentUrlBuilder('') : '',
            );
        }

		return new CategoryFilter(
			items: $filterItems,
			resetItem: $resetItem,
			activeUids: $activeUids,
		);
    }

    // -------------------------------------------------------------------------
    // Tree recursion
    // -------------------------------------------------------------------------

    /**
     * Recursively builds a tree item with its children.
     *
     * $disabledLevels and $siblingExclusiveLevels are passed explicitly through
     * recursion (decremented each level) rather than via CategoryItemConfig, so
     * that negative values — which are valid config — are never confused with
     * an uninitialised sentinel.
     */
    protected function buildTree(
        array                      $category,
        array                      $siblings,
        int                        $depth,
        int                        $disabledLevels,
        int                        $siblingExclusiveLevels,
        bool                       $buildTreeBelowEnabledInactive,
        CategoryFilterBuildContext $context,
        array                      $activeSiblings = [],
        string                     $parentFallbackUid = '',
    ): CategoryFilterItem {
        $disabled = $disabledLevels > 0;
        $siblingExclusive = $siblingExclusiveLevels > 0;
        $isActive = $this->isActive($category['uid'], $context->activeUids);

        $shouldRecurse = $depth > 0
            && ($disabled || $isActive || $buildTreeBelowEnabledInactive);

        if (!$shouldRecurse) {
            return $this->buildFilterItem(
                $category,
                $context,
                disabled: $disabled,
                siblingExclusive: $siblingExclusive,
                activeSiblings: $activeSiblings,
                parentFallbackUid: $parentFallbackUid,
            );
        }

        $subCategories = $this->categoryRepository->findByParent($category['uid'], true);

        $activeChildren = array_values(array_filter(
            array_column($subCategories, 'uid'),
            fn($uid) => $this->isActive($uid, $context->activeUids)
        ));

        $activeSiblingsAtThisLevel = array_values(array_filter(
            array_column($siblings, 'uid'),
            fn($uid) => $uid !== $category['uid'] && $this->isActive($uid, $context->activeUids)
        ));

        $item = $this->buildFilterItem(
            $category,
            $context,
            disabled: $disabled,
            siblingExclusive: $siblingExclusive,
            activeChildren: $activeChildren,
            activeSiblings: $activeSiblingsAtThisLevel,
            parentFallbackUid: $parentFallbackUid,
        );

        foreach ($subCategories as $subCategory) {
            $item->children[] = $this->buildTree(
                $subCategory,
                $subCategories,
                $depth - 1,
                $this->decrementLevelCounter($disabledLevels),
                $this->decrementLevelCounter($siblingExclusiveLevels),
                $buildTreeBelowEnabledInactive,
                $context,
                activeSiblings: $activeChildren,
                parentFallbackUid: !$disabled ? (string)$category['uid'] : '',
            );
        }

        return $item;
    }

    // -------------------------------------------------------------------------
    // Item construction
    // -------------------------------------------------------------------------

    protected function buildFilterItem(
        array                      $category,
        CategoryFilterBuildContext $context,
        bool                       $disabled = false,
        bool                       $siblingExclusive = false,
        array                      $activeChildren = [],
        array                      $activeSiblings = [],
        string                     $parentFallbackUid = '',
    ): CategoryFilterItem {
        $uid = (string)$category['uid'];
        $isActive = $this->isActive((int)$uid, $context->activeUids);
        $label = $this->resolveLabel($category, $context->labelField, $context->labelFieldFallback);
        $fragmentUrl = fn(string $uids) => $context->fragmentUrlBuilder ? ($context->fragmentUrlBuilder)($uids) : '';

        $closeItem = null;
        if ($activeChildren) {
            $closeUids = implode(',', array_diff(
                GeneralUtility::intExplode(',', $context->activeUids, true),
                $activeChildren
            ));
            $closeItem = new CategoryFilterItem(
                label: (string)count($activeChildren),
                url: ($context->urlBuilder)($closeUids),
                fragmentUrl: $fragmentUrl($closeUids),
            );
        }

        if ($disabled) {
            return new CategoryFilterItem(
                label: $label,
                closeItem: $closeItem,
                active: $isActive,
                disabled: true,
                activeChildren: $activeChildren,
            );
        }

        $newUids = $this->resolveToggleUids(
            $uid, $isActive, $siblingExclusive, $activeSiblings, $parentFallbackUid,
            $context->activeUids, $context->multiSelect,
        );

        $exclusiveItem = new CategoryFilterItem(
            label: $label,
            url: ($context->urlBuilder)($uid),
            fragmentUrl: $fragmentUrl($uid),
            active: $isActive && $context->activeUids === $uid,
        );

        $potentialCount = $context->checkPotential
            ? $this->getPotentialCount($newUids, $context)
            : null;

        return new CategoryFilterItem(
            label: $label,
            url: ($context->urlBuilder)($newUids),
            fragmentUrl: $fragmentUrl($newUids),
            closeItem: $closeItem,
            active: $isActive,
            potentialCount: $potentialCount,
            activeChildren: $activeChildren,
            exclusiveItem: $exclusiveItem,
        );
    }

    // -------------------------------------------------------------------------
    // Toggle UID resolution
    // -------------------------------------------------------------------------

    protected function resolveToggleUids(
        string $uid,
        bool   $isActive,
        bool   $siblingExclusive,
        array  $activeSiblings,
        string $parentFallbackUid,
        string $activeUids,
        bool   $multiSelect,
    ): string {
        $current = GeneralUtility::intExplode(',', $activeUids, true);

        if ($isActive) {
            $remaining = array_diff($current, [(int)$uid]);
            if (!$remaining && $parentFallbackUid !== '') {
                return $parentFallbackUid;
            }
            return implode(',', $remaining);
        }

        if (!$multiSelect) {
            return $uid;
        }

        if ($siblingExclusive) {
            $without = array_diff($current, $activeSiblings);
            $without[] = (int)$uid;
            return implode(',', array_unique($without));
        }

        $current[] = (int)$uid;
        return implode(',', array_unique($current));
    }

    // -------------------------------------------------------------------------
    // Potential checking
    // -------------------------------------------------------------------------

    protected function getPotentialCount(string $newUids, CategoryFilterBuildContext $context): ?int
    {
        if ($newUids === '' || $context->potentialRepository === null || $context->potentialDemand === null) {
            return null;
        }
        $demand = clone $context->potentialDemand;
        $demand->categoryGroups[$context->potentialGroupKey]['uids'] = $newUids;
        $demand->limit = null;
        return count($context->potentialRepository->findByMenuDemand($demand, true));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function isActive(int $uid, string $activeUids): bool
    {
        return $activeUids !== '' && GeneralUtility::inList($activeUids, (string)$uid);
    }

    protected function resolveLabel(array $category, string $labelField, string $labelFieldFallback): string
    {
        return (string)(
            ($category[$labelField] ?? '') ?:
            ($category[$labelFieldFallback] ?? '') ?:
            ($category['title'] ?? '')
        );
    }

    /**
     * Decrements a level counter by one step towards zero, preserving sign.
     *
     * Positive N → N-1 (counting down from top)
     * Negative -N → -N+1 (counting up from bottom towards zero)
     * Zero → stays zero
     */
    protected function decrementLevelCounter(int $value): int
    {
        if ($value > 1) return $value - 1;
        if ($value < 0) return $value + 1;
        return 0;
    }
}
