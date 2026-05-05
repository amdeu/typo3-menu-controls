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
 * Builder for CategoryFilter DTOs.
 *
 * Stateless with respect to the HTTP request. The builder receives:
 *   - an ordered list of CategoryItemConfig objects (UIDs + tree settings)
 *   - the currently active category UIDs as a plain string
 *   - URL-building closures from the controller
 *
 * The builder owns all category DB interaction — it fetches root records
 * and recursively fetches children as needed. The controller is responsible
 * only for deciding which UIDs to include, reading the active UIDs from the
 * request, and providing the URL-building closures.
 *
 * Basic usage:
 *
 *   $activeUids = $request->getArgument('demand')['categories']['topics']['uids'] ?? '';
 *
 *   $filter = (new CategoryFilterBuilder($categoryRepository))
 *       ->withActiveUids($activeUids)
 *       ->withMultiSelect(true)
 *       ->withUrlBuilder(fn(string $uids) => $this->uriBuilder->uriFor('list', [
 *           'demand' => ['categories' => ['topics' => ['uids' => $uids]]]
 *       ]))
 *       ->withFragmentUrlBuilder(fn(string $uids) => ...)
 *       ->build([
 *           new CategoryItemConfig(uid: 5),
 *           new CategoryItemConfig(uid: 12, depth: 2, disabledLevels: 1),
 *           new CategoryItemConfig(uid: 8),
 *       ]);
 */
#[Autoconfigure(public: true)]
class CategoryFilterBuilder
{
    /**
     * Currently active category UIDs as a comma-separated string.
     * Provided by the controller from the current request arguments.
     */
    protected string $activeUids = '';

    /**
     * Master multi-select switch.
     * When false, selecting any item replaces the entire current selection.
     * When true, per-level behaviour is governed by CategoryItemConfig::$siblingExclusiveLevels.
     */
    protected bool $multiSelect = true;

    /**
     * Builds a standard (absolute) URL for a given filter state.
     * Receives the new active UIDs string; returns a URL string.
     *
     * @var \Closure(string $activeUids): string
     */
    protected \Closure $urlBuilder;

    /**
     * Builds a fragment/AJAX URL for a given filter state.
     * When not provided, fragmentUrl on all items will be an empty string.
     *
     * @var \Closure(string $activeUids): string|null
     */
    protected ?\Closure $fragmentUrlBuilder = null;

    /**
     * Label for the reset item. When empty, no reset item is produced.
     */
    protected string $resetLabel = 'Reset';

    /**
     * Whether to check if selecting a category would yield any results.
     */
    protected bool $checkPotential = false;

    /**
     * Repository used for DB-based potential checking (N+1 per item).
     */
    protected ?MenuDemandRepositoryInterface $potentialRepository = null;

    /**
     * Base demand cloned per-item for potential checking.
     */
    protected ?MenuDemand $potentialDemand = null;

    /**
     * Key within MenuDemand::$categoryGroups to override when cloning
     * the demand for potential checks.
     */
    protected string $potentialGroupKey = '0';

    /**
     * Category record field to use as the display label.
     */
    protected string $labelField = 'title';

    /**
     * Fallback label field when $labelField is empty on a record.
     */
    protected string $labelFieldFallback = 'title';

    public function __construct(
        protected CategoryRepository $categoryRepository,
    ) {
        $this->urlBuilder = fn(string $uids) => '';
    }

    // -------------------------------------------------------------------------
    // Fluent configuration
    // -------------------------------------------------------------------------

    /**
     * Sets the currently active category UIDs (comma-separated string).
     * The controller reads this from the request and passes it in explicitly —
     * the builder does not touch the request.
     */
    public function withActiveUids(string $activeUids): self
    {
        $this->activeUids = $activeUids;
        return $this;
    }

    /**
     * Sets the global multi-select flag.
     * When false the entire filter behaves as single-select regardless of
     * CategoryItemConfig::$siblingExclusiveLevels values.
     */
    public function withMultiSelect(bool $multiSelect): self
    {
        $this->multiSelect = $multiSelect;
        return $this;
    }

    /**
     * Sets the URL builder closure.
     * Receives the new active UIDs string; must return an absolute URL string.
     *
     * @param \Closure(string $activeUids): string $urlBuilder
     */
    public function withUrlBuilder(\Closure $urlBuilder): self
    {
        $this->urlBuilder = $urlBuilder;
        return $this;
    }

    /**
     * Sets the fragment/AJAX URL builder closure.
     * Receives the new active UIDs string; must return a relative URL string.
     * When not provided, fragmentUrl on all items will be an empty string.
     *
     * @param \Closure(string $activeUids): string $fragmentUrlBuilder
     */
    public function withFragmentUrlBuilder(\Closure $fragmentUrlBuilder): self
    {
        $this->fragmentUrlBuilder = $fragmentUrlBuilder;
        return $this;
    }

    /**
     * Sets the label for the reset filter item.
     * Pass an empty string to suppress the reset item entirely.
     */
    public function withResetLabel(string $resetLabel): self
    {
        $this->resetLabel = $resetLabel;
        return $this;
    }

    /**
     * Enables potential count checking (one query per filter item).
     * The demand is cloned per item; the active UIDs for the given group key
     * are replaced before querying. The result count is stored on each
     * CategoryFilterItem as $potentialCount — null when not configured,
     * 0 when selecting the item would yield no results.
     *
     * @param string $groupKey Key within MenuDemand::$categoryGroups to override
     */
    public function withCheckPotential(
		bool $checkPotential,
        MenuDemandRepositoryInterface $repository,
        MenuDemand $demand,
        string $groupKey,
    ): self {
		$this->checkPotential = $checkPotential;
		$this->potentialRepository = $repository;
        $this->potentialDemand = $demand;
        $this->potentialGroupKey = $groupKey;
        return $this;
    }

    /**
     * Sets the category record field used as the display label.
     * Falls back to $fallback when the primary field is empty on a record.
     */
    public function withLabelField(string $field, string $fallback = 'title'): self
    {
        $this->labelField = $field;
        $this->labelFieldFallback = $fallback;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Build
    // -------------------------------------------------------------------------

    /**
     * Builds the CategoryFilter from an ordered list of CategoryItemConfig objects.
     *
     * The builder fetches each root category record by UID, then recurses into
     * children as configured. Configs are processed in the order provided, so
     * flat items and tree roots can be freely interleaved.
     *
     * @param CategoryItemConfig[] $configs
     */
    public function build(array $configs): CategoryFilter
    {
        // Fetch all root category records in one query
        $uids = implode(',', array_map(fn(CategoryItemConfig $c) => $c->uid, $configs));
        $rootRecords = $this->categoryRepository->findByUidList($uids, true);
        $rootRecordsByUid = array_column($rootRecords, null, 'uid');

        // Collect all sibling records at the root level for exclusivity computation
        $rootSiblings = array_values($rootRecordsByUid);

        $filterItems = [];
        foreach ($configs as $config) {
            $category = $rootRecordsByUid[$config->uid] ?? null;
            if ($category === null) {
                continue;
            }
            $filterItems[] = $config->depth > 0
                ? $this->buildTree(
                    $category,
                    $rootSiblings,
                    $config->depth,
                    $config->disabledLevels,
                    $config->siblingExclusiveLevels,
                    $config->buildTreeBelowEnabledInactive,
                )
                : $this->buildFilterItem(
                    $category,
                    disabled: false,
                    siblingExclusive: !$this->multiSelect || $config->siblingExclusiveLevels !== 0,
                    activeSiblings: array_values(array_filter(
                        array_column($rootSiblings, 'uid'),
                        fn($uid) => $uid !== $config->uid && $this->isActive($uid)
                    )),
                );
        }

        $resetItem = null;
        if ($this->resetLabel !== '' && $this->activeUids !== '') {
            $resetItem = new CategoryFilterItem(
                label: $this->resetLabel,
                url: ($this->urlBuilder)(''),
                fragmentUrl: $this->fragmentUrl(''),
            );
        }

        return new CategoryFilter(
            items: $filterItems,
            resetItem: $resetItem,
        );
    }

    // -------------------------------------------------------------------------
    // Tree recursion
    // -------------------------------------------------------------------------

    /**
     * Recursively builds a tree item with its children.
     *
     * @param array $category Current category record (raw DB row)
     * @param array $siblings All sibling records at this level (used for exclusivity)
     * @param int $depth Remaining levels to recurse into
     * @param int $disabledLevels Remaining non-selectable levels counter
     * @param int $siblingExclusiveLevels Remaining sibling-exclusivity levels counter
     * @param bool $buildTreeBelowEnabledInactive Whether to show children of enabled-but-inactive parents
     * @param array $activeSiblings UIDs of currently active siblings at this level
     * @param string $parentFallbackUid UID to restore when deselecting the last active item in a branch
     */
    protected function buildTree(
        array  $category,
        array  $siblings,
        int    $depth,
        int    $disabledLevels,
        int    $siblingExclusiveLevels,
        bool   $buildTreeBelowEnabledInactive,
        array  $activeSiblings = [],
        string $parentFallbackUid = '',
    ): CategoryFilterItem
    {
        $disabled = $disabledLevels > 0;
        $siblingExclusive = $siblingExclusiveLevels > 0;
        $isActive = $this->isActive($category['uid']);

        // Stop recursing when depth is exhausted, or when the item is
        // enabled+inactive and we are not configured to build below such items.
        $shouldRecurse = $depth > 0
            && ($disabled || $isActive || $buildTreeBelowEnabledInactive);

        if (!$shouldRecurse) {
            return $this->buildFilterItem(
                $category,
                disabled: $disabled,
                siblingExclusive: $siblingExclusive,
                activeSiblings: $activeSiblings,
                parentFallbackUid: $parentFallbackUid,
            );
        }

        $subCategories = $this->categoryRepository->findByParent($category['uid'], true);

        $activeChildren = array_values(array_filter(
            array_column($subCategories, 'uid'),
            fn($uid) => $this->isActive($uid)
        ));

        $activeSiblingsAtThisLevel = array_values(array_filter(
            array_column($siblings, 'uid'),
            fn($uid) => $uid !== $category['uid'] && $this->isActive($uid)
        ));

        $item = $this->buildFilterItem(
            $category,
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
                activeSiblings: $activeChildren,
                parentFallbackUid: !$disabled ? (string)$category['uid'] : '',
            );
        }

        return $item;
    }

    // -------------------------------------------------------------------------
    // Item construction
    // -------------------------------------------------------------------------

    /**
     * Builds a single CategoryFilterItem.
     *
     * Resolves the new active UIDs string that results from toggling this item,
     * then delegates all URL construction to the injected closures.
     *
     * @param array $category Raw category DB row
     * @param bool $disabled Whether this item is a non-selectable structural element
     * @param bool $siblingExclusive Whether selecting this item deselects its siblings
     * @param int[] $activeChildren UIDs of currently active child categories
     * @param int[] $activeSiblings UIDs of currently active siblings at this level
     * @param string $parentFallbackUid UID to restore when deselecting the last active item in a branch
     */
    protected function buildFilterItem(
        array  $category,
        bool   $disabled = false,
        bool   $siblingExclusive = false,
        array  $activeChildren = [],
        array  $activeSiblings = [],
        string $parentFallbackUid = '',
    ): CategoryFilterItem
    {
        $uid = (string)$category['uid'];
        $isActive = $this->isActive((int)$uid);
        $label = $this->resolveLabel($category);

        // Close item: deselects all active children, keeping other active UIDs intact
        $closeItem = null;
        if ($activeChildren) {
            $closeUids = implode(',', array_diff(
                GeneralUtility::intExplode(',', $this->activeUids, true),
                $activeChildren
            ));
            $closeItem = new CategoryFilterItem(
                label: (string)count($activeChildren),
                url: ($this->urlBuilder)($closeUids),
                fragmentUrl: $this->fragmentUrl($closeUids),
            );
        }

        // Disabled items are structural only — no URL, no toggle
        if ($disabled) {
            return new CategoryFilterItem(
                label: $label,
                closeItem: $closeItem,
                active: $isActive,
                disabled: true,
                activeChildren: $activeChildren,
            );
        }

        $newUids = $this->resolveToggleUids($uid, $isActive, $siblingExclusive, $activeSiblings, $parentFallbackUid);

        // Exclusive item: selects only this category, deselecting everything else
        $exclusiveItem = new CategoryFilterItem(
            label: $label,
            url: ($this->urlBuilder)($uid),
            fragmentUrl: $this->fragmentUrl($uid),
            active: $isActive && $this->activeUids === $uid,
        );

        $potentialCount = $this->checkPotential
            ? $this->getPotentialCount($newUids)
            : null;

        return new CategoryFilterItem(
            label: $label,
            url: ($this->urlBuilder)($newUids),
            fragmentUrl: $this->fragmentUrl($newUids),
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

    /**
     * Resolves the new comma-separated active UIDs string that results from
     * toggling the given category, respecting the global multiSelect flag and
     * per-level sibling exclusivity.
     *
     * @param string $uid The category being toggled
     * @param bool $isActive Whether it is currently active
     * @param bool $siblingExclusive Whether selecting this item deselects its siblings
     * @param int[] $activeSiblings Currently active siblings at this level
     * @param string $parentFallbackUid UID to restore when deselecting the last active item in a branch
     */
    protected function resolveToggleUids(
        string $uid,
        bool   $isActive,
        bool   $siblingExclusive,
        array  $activeSiblings,
        string $parentFallbackUid,
    ): string
    {
        $current = GeneralUtility::intExplode(',', $this->activeUids, true);

        if ($isActive) {
            $remaining = array_diff($current, [(int)$uid]);
            if (!$remaining && $parentFallbackUid !== '') {
                return $parentFallbackUid;
            }
            return implode(',', $remaining);
        }

        // Global single-select: replace everything
        if (!$this->multiSelect) {
            return $uid;
        }

        // Sibling-exclusive: remove active siblings first, then add this UID
        if ($siblingExclusive) {
            $without = array_diff($current, $activeSiblings);
            $without[] = (int)$uid;
            return implode(',', array_unique($without));
        }

        // Fully additive
        $current[] = (int)$uid;
        return implode(',', array_unique($current));
    }

    // -------------------------------------------------------------------------
    // Potential checking
    // -------------------------------------------------------------------------

    /**
     * Returns the number of records that would match if the given UID list
     * were applied as the active filter state, or null if potential checking
     * is not configured. A count of 0 means selecting this item yields no results.
     */
    protected function getPotentialCount(string $newUids): ?int
    {
        if ($newUids === '' || $this->potentialRepository === null || $this->potentialDemand === null) {
            return null;
        }
        $demand = clone $this->potentialDemand;
        $demand->categoryGroups[$this->potentialGroupKey]['uids'] = $newUids;
        $demand->limit = null;
        return count($this->potentialRepository->findByMenuDemand($demand, true));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function isActive(int $uid): bool
    {
        return $this->activeUids !== '' && GeneralUtility::inList($this->activeUids, (string)$uid);
    }

    protected function fragmentUrl(string $uids): string
    {
        return $this->fragmentUrlBuilder ? ($this->fragmentUrlBuilder)($uids) : '';
    }

    /**
     * Resolves the display label for a category record, falling back through
     * configured fields to 'title' as a last resort.
     */
    protected function resolveLabel(array $category): string
    {
        return (string)(
            ($category[$this->labelField] ?? '') ?:
            ($category[$this->labelFieldFallback] ?? '') ?:
            ($category['title'] ?? '')
        );
    }

    /**
     * Decrements a level counter by one step towards zero, preserving sign.
     *
     * Used to advance disabledLevels and siblingExclusiveLevels as the tree
     * recurses deeper. Positive values count down from the top; negative values
     * count up from the bottom towards zero.
     *
     *   N  > 1  →  N - 1  (still counting down from top)
     *   N  = 1  →  0      (exhausted, next level is unaffected)
     *   N  = 0  →  0      (no-op)
     *   N  < 0  →  N + 1  (counting up from bottom towards zero)
     */
    protected function decrementLevelCounter(int $value): int
    {
        if ($value > 1) return $value - 1;
        if ($value < 0) return $value + 1;
        return 0;
    }
}
