<?php

declare(strict_types=1);

namespace UBOS\MenuControls\Dto;

/**
 * Configuration for a single category item within a CategoryFilter.
 *
 * Passed to CategoryFilterBuilder::build() as an ordered list.
 * The builder fetches the category record and any children itself —
 * the controller only decides which UIDs to include and how to configure them.
 *
 * Flat item (no children):
 *
 *   new CategoryItemConfig(uid: 5)
 *
 * Tree root — top level is a non-selectable header,
 * children are sibling-exclusive:
 *
 *   new CategoryItemConfig(
 *       uid: 12,
 *       depth: 2,
 *       disabledLevels: 1,
 *       siblingExclusiveLevels: -1,
 *   )
 *
 * Three-level tree — top two levels sibling-exclusive, leaves additive:
 *
 *   new CategoryItemConfig(
 *       uid: 7,
 *       depth: 3,
 *       siblingExclusiveLevels: 2,
 *   )
 */
readonly class CategoryItemConfig
{
    /**
     * @param int $uid UID of the category record to render as a filter item.
     *   The builder fetches the record and recursively fetches children as needed.
     *
     * @param int $depth How many levels deep to recurse into child categories.
     *   0 = flat (no children fetched), 1 = one level of children, etc.
     *
     * @param int $disabledLevels How many levels from the top are non-selectable.
     *   Disabled items render without a URL — useful for collapsible group headers.
     *   0 = all levels selectable.
     *   1 = top level is a header only, children are selectable.
     *   2 = top two levels are headers, and so on.
     *   Must be less than $depth to have any selectable items.
     *
     * @param int $siblingExclusiveLevels Controls whether selecting an item deselects
     *   its siblings at each tree level. Only meaningful when the global multiSelect
     *   flag on the builder is true — when multiSelect is false the entire filter
     *   is already single-select regardless of this value.
     *
     *   Positive N: the top N levels are sibling-exclusive (selecting one item at that
     *   level deselects its siblings). Deeper levels are additive.
     *
     *   Negative -N: the bottom N levels are sibling-exclusive.
     *   Upper levels are additive.
     *
     *   0: all levels fully additive (default).
     *
     *   Examples for a 3-level tree:
     *     siblingExclusiveLevels:  2  → levels 1+2 exclusive, level 3 additive
     *     siblingExclusiveLevels: -2  → level 1 additive, levels 2+3 exclusive
     *     siblingExclusiveLevels:  1  → level 1 exclusive, levels 2+3 additive
     *     siblingExclusiveLevels: -1  → levels 1+2 additive, level 3 exclusive
     *     siblingExclusiveLevels:  0  → all levels additive
     *
     * @param bool $buildTreeBelowEnabledInactive Whether to render children of a
     *   selectable (non-disabled) parent that is not currently active.
     *   false (default): children only shown below disabled parents or active parents.
     *   true: children always rendered regardless of the parent's active state.
     *   Typically false for on-demand expanding filters, true for always-visible trees.
     */
    public function __construct(
        public int  $uid,
        public int  $depth = 0,
        public int  $disabledLevels = 0,
        public int  $siblingExclusiveLevels = 0,
        public bool $buildTreeBelowEnabledInactive = false,
    ) {}
}
