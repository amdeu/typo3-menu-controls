<?php

declare(strict_types=1);

namespace UBOS\MenuControls\Dto;

/**
 * Data Transfer Object for menu filtering criteria.
 *
 * Encapsulates all parameters needed to query a filtered, sorted, and
 * paginated set of records from any repository implementing
 * MenuDemandRepositoryInterface.
 *
 * Designed to be populated either programmatically or directly from a
 * FlexForm settings array via createFromArray().
 */
class MenuDemand
{
    /**
     * @param string $parents Comma-separated list of storage page UIDs.
     *   Records whose pid matches any of these will be included.
     *
     * @param string $records Comma-separated list of specific record UIDs.
     *   When set, only these records (and their localized variants) are returned.
     *
     * @param int|null $limit Maximum number of records to return.
     *   Null means no limit.
     *
     * @param int $offset Number of records to skip (for manual pagination).
     *
     * @param array<string, array{uids: string, conjunction: string}> $categoryGroups
     *   Category filter groups. Each entry is keyed by an arbitrary group identifier
     *   and contains:
     *     - uids: comma-separated category UIDs to filter by
     *     - conjunction: how to combine UIDs within the group (or, and, notor, notand)
     *   Multiple groups are combined using $categoryGroupsConjunction.
     *
     * @param string $categoryGroupsConjunction How to combine multiple category groups.
     *   Accepts: 'and', 'or', 'notand', 'notor' (default: 'and').
     *
     * @param string $orderField Database field to sort results by (default: 'sorting').
     *
     * @param string $orderDirection Sort direction: 'asc' or 'desc' (default: 'asc').
     *
     * @param bool $orderByRecordsProperty When true, results are reordered in PHP to
     *   match the sequence of UIDs in $records. Records not in $records are appended.
     *
     * @param array $additionalSettings Arbitrary extra settings for use in
     *   getAdditionalMenuDemandConstraints() of consuming repositories.
     *   Keeps the core DTO generic while allowing per-implementation extensions.
     */
    public function __construct(
        public string  $parents = '',
        public string  $records = '',
        public ?int    $limit = null,
        public int     $offset = 0,
        public array   $categoryGroups = [],
        public string  $categoryGroupsConjunction = 'and',
        public string  $orderField = 'sorting',
        public string  $orderDirection = 'asc',
        public bool    $orderByRecordsProperty = false,
        public array   $additionalSettings = [],
    ) {}

    /**
     * Creates a MenuDemand from a raw array, typically from FlexForm plugin settings.
     *
     * Keys map directly to constructor parameters. Unknown keys are ignored.
     * The 'categoryGroups' key replaces the old 'categories' key.
     */
    public static function createFromArray(array $demand): self
    {
        return new self(
            parents: $demand['parents'] ?? '',
            records: $demand['records'] ?? '',
            limit: isset($demand['limit']) ? (int)$demand['limit'] : null,
            offset: (int)($demand['offset'] ?? 0),
            categoryGroups: $demand['categoryGroups'] ?? [],
            categoryGroupsConjunction: $demand['categoryGroupsConjunction'] ?? 'and',
            orderField: $demand['orderField'] ?? 'sorting',
            orderDirection: $demand['orderDirection'] ?? 'asc',
            orderByRecordsProperty: (bool)($demand['orderByRecordsProperty'] ?? false),
            additionalSettings: $demand['additionalSettings'] ?? [],
        );
    }
}
