<?php

declare(strict_types=1);

namespace Amdeu\MenuControls\Domain\Repository;

use TYPO3\CMS\Core\Domain\RecordFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\ConstraintInterface;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use Amdeu\MenuControls\Dto\MenuDemand;

/**
 * Provides a complete implementation of findByMenuDemand() for any Extbase repository.
 *
 * Usage — add to your repository alongside the interface:
 *
 *   class NewsRepository extends Repository implements MenuDemandRepositoryInterface
 *   {
 *       use FindByMenuDemandRepositoryTrait;
 *
 *       protected function getAdditionalMenuDemandConstraints(QueryInterface $query, MenuDemand $demand): array
 *       {
 *           return [
     *               $query->greaterThan('datetime', $demand->additionalSettings['cutoffDate'] ?? 0),
 *           ];
 *       }
 *   }
 */
trait FindByMenuDemandRepositoryTrait
{
    /**
     * Required by all Extbase repositories — provides the query factory.
     */
    abstract public function createQuery();

    /**
     * Maps raw DB rows to domain objects using Extbase's DataMapper.
     * Useful when you have raw query results and need hydrated models.
     *
     * @param array $rows Raw associative DB rows
     * @return array Mapped domain objects
     */
    public function map(array $rows): array
    {
        return GeneralUtility::makeInstance(DataMapper::class)->map($this->objectType, $rows);
    }

	/**
	 * Maps raw DB rows to Record objects using RecordFactory.
	 * Use in combination with findByMenuDemand($demand, true):
	 *
	 *   $records = $this->mapToRecords(
	 *       $this->findByMenuDemand($demand, true)
	 *   );
	 */
	public function mapToRecords(array $rows): array
	{
		$tableName = GeneralUtility::makeInstance(DataMapper::class)
			->getDataMap($this->objectType)
			->getTableName();
		$recordFactory = GeneralUtility::makeInstance(RecordFactory::class);
		return array_map(
			fn(array $row) => $recordFactory->createResolvedRecordFromDatabaseRow($tableName, $row),
			$rows
		);
	}

    /**
     * Hook for adding repository-specific constraints to a MenuDemand query.
     *
     * Override this in your repository to add constraints based on record-specific
     * fields or values from $demand->additionalSettings.
     *
     * @return ConstraintInterface[] Additional constraints to AND into the query
     */
    protected function getAdditionalMenuDemandConstraints(QueryInterface $query, MenuDemand $demand): array
    {
        return [];
    }

    /**
     * Finds records matching the given MenuDemand.
     *
     * Handles: storage page / specific record selection, category group filtering,
     * ordering, limit/offset, and optional PHP-side reordering by $records sequence.
     *
     * @param MenuDemand $demand The demand object containing search criteria
     * @param bool $returnRawQueryResult When true, returns raw associative DB rows
     *   instead of mapped domain objects
     * @return array The matched records
     */
    public function findByMenuDemand(MenuDemand $demand, bool $returnRawQueryResult = false): array
    {
        $query = $this->createQuery();
        $constraints = $this->getAdditionalMenuDemandConstraints($query, $demand);

        // Storage page / specific record constraints
        $scopeConstraints = [];
        if ($demand->records) {
            foreach (GeneralUtility::intExplode(',', $demand->records, true) as $uid) {
                $scopeConstraints[] = $query->equals('uid', $uid);
                $scopeConstraints[] = $query->equals('l10n_parent', $uid);
            }
        }
        if ($demand->parents) {
            foreach (GeneralUtility::intExplode(',', $demand->parents, true) as $pid) {
                $scopeConstraints[] = $query->equals('pid', $pid);
            }
        }
        if ($scopeConstraints) {
            $constraints[] = $query->logicalOr(...$scopeConstraints);
        }

        // Category group constraints
        $categoryGroupConstraints = [];
        foreach ($demand->categoryGroups as $group) {
            if ($group['uids'] ?? '') {
                $groupConstraint = $this->buildCategoryGroupConstraint(
                    $query,
                    $group['uids'],
                    $group['conjunction'] ?? 'or'
                );
                if ($groupConstraint) {
                    $categoryGroupConstraints[] = $groupConstraint;
                }
            }
        }
        if ($categoryGroupConstraints) {
            $constraints[] = match (strtolower($demand->categoryGroupsConjunction)) {
                'or'     => $query->logicalOr(...$categoryGroupConstraints),
                'notor'  => $query->logicalNot($query->logicalOr(...$categoryGroupConstraints)),
                'notand' => $query->logicalNot($query->logicalAnd(...$categoryGroupConstraints)),
                default  => $query->logicalAnd(...$categoryGroupConstraints),
            };
        }

        // Limit and offset
        if ($demand->limit) {
            $query->setLimit($demand->limit);
        }
        if ($demand->offset) {
            $query->setOffset($demand->offset);
        }

        $query->setOrderings($this->getMenuDemandOrderings($demand));

        // Fallback constraint when nothing else is set — avoids an empty WHERE clause
        if (!$constraints) {
            $constraints[] = $query->greaterThan('uid', 0);
        }

        $records = $query->matching($query->logicalAnd(...$constraints))->execute($returnRawQueryResult);

        if (!$returnRawQueryResult) {
            $records = $records->toArray();
        }

        // PHP-side reordering to match the sequence of UIDs in $demand->records
        if ($demand->orderByRecordsProperty && $demand->records) {
            $records = $this->reorderByRecordsSequence($records, $demand->records);
        }

        return $records;
    }

    /**
     * Returns the orderings array for a MenuDemand query.
     * Override to customise ordering for record types with different field names.
     */
    protected function getMenuDemandOrderings(MenuDemand $demand): array
    {
        $direction = strtolower($demand->orderDirection) === 'desc'
            ? QueryInterface::ORDER_DESCENDING
            : QueryInterface::ORDER_ASCENDING;
		// strtolower for legacy camelCase columns like "lastUpdated", will be interpreted as "last_updated" otherwise and throw an error
        $orderings = [strtolower($demand->orderField) => $direction];
        if ($demand->orderField !== 'sorting') {
            $orderings['sorting'] = QueryInterface::ORDER_ASCENDING;
        }
        return $orderings;
    }

    /**
     * Builds a constraint for a single category group.
     * Supports conjunctions: or, and, notor, notand.
     *
     * @param QueryInterface $query
     * @param string $uids Comma-separated category UIDs
     * @param string $conjunction How to combine individual category constraints
     */
    protected function buildCategoryGroupConstraint(
        QueryInterface $query,
        string         $uids,
        string         $conjunction,
    ): ?ConstraintInterface
    {
        $categoryUids = GeneralUtility::intExplode(',', $uids, true);
        if (!$categoryUids) {
            return null;
        }

        $categoryConstraints = array_map(
            fn(int $uid) => $query->contains('categories', $uid),
            $categoryUids
        );

        return match (strtolower($conjunction)) {
            'and'    => $query->logicalAnd(...$categoryConstraints),
            'notor'  => $query->logicalNot($query->logicalOr(...$categoryConstraints)),
            'notand' => $query->logicalNot($query->logicalAnd(...$categoryConstraints)),
            default  => $query->logicalOr(...$categoryConstraints),
        };
    }

    /**
     * Reorders records in PHP to match the sequence of UIDs in $demand->records.
     * Records whose UID appears in the sequence are placed at that position.
     * Records not in the sequence are appended at the end in their original order.
     *
     * @param array $records Fetched records (raw rows or domain objects)
     * @param string $recordUids Comma-separated UID sequence to match
     * @return array Reordered records
     */
    protected function reorderByRecordsSequence(array $records, string $recordUids): array
    {
        $sequence = GeneralUtility::intExplode(',', $recordUids, true);
        $ordered = [];
        $remainder = [];

        foreach ($records as $record) {
            $uid = is_array($record) ? (int)$record['uid'] : $record->getUid();
            $position = array_search($uid, $sequence, true);
            if ($position !== false) {
                $ordered[$position] = $record;
            } else {
                $remainder[] = $record;
            }
        }

        ksort($ordered);
        return array_values([...$ordered, ...$remainder]);
    }
}
