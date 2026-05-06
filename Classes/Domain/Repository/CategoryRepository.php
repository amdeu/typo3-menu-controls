<?php

declare(strict_types=1);

namespace Amdeu\MenuControls\Domain\Repository;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\QueryResult;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Repository for 'sys_category' records.
 */
#[Autoconfigure(public: true)]
class CategoryRepository extends Repository
{
    protected $defaultOrderings = [
        'sorting' => QueryInterface::ORDER_ASCENDING,
    ];

    public function initializeObject(): void
    {
        $querySettings = GeneralUtility::makeInstance(Typo3QuerySettings::class);
        $querySettings->setRespectStoragePage(false);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * Finds categories by a comma-separated list of UIDs, including localized versions.
     *
     * Ordering priority: orderByList > orderField > defaultOrderings (sorting ASC).
     *
     * @param string $uids             Comma-separated category UIDs
     * @param bool   $returnRawQueryResult
     * @param string $orderField       DB field to order by; empty = use defaultOrderings
     * @param string $orderDirection   'asc' or 'desc'
     * @param bool   $orderByList      When true, results are reordered in PHP to match
     *                                 the sequence of $uids, superseding $orderField
     */
    public function findByUidList(
        string $uids,
        bool   $returnRawQueryResult = false,
        string $orderField = '',
        string $orderDirection = 'asc',
        bool   $orderByList = false,
    ): array|QueryResult {
        if (!$uids) {
            return [];
        }

        $query = $this->createQuery();

        if (!$orderByList && $orderField !== '') {
            $direction = strtolower($orderDirection) === 'desc'
                ? QueryInterface::ORDER_DESCENDING
                : QueryInterface::ORDER_ASCENDING;
            $query->setOrderings([
                $orderField => $direction,
                'sorting'   => QueryInterface::ORDER_ASCENDING,
            ]);
        }

        $result = $query
            ->matching(
                $query->logicalOr(
                    $query->in('l10n_parent', explode(',', $uids)),
                    $query->in('uid', explode(',', $uids))
                )
            )
            ->execute($returnRawQueryResult);

        if (!$orderByList) {
            return $result;
        }

        // Reorder results to match the sequence of UIDs in $uids
        $sequence = GeneralUtility::intExplode(',', $uids, true);
        $indexed = [];
        foreach ($result as $record) {
            $uid = is_array($record) ? (int)$record['uid'] : $record->getUid();
            $indexed[$uid] = $record;
        }
        return array_values(array_filter(
            array_map(fn(int $uid) => $indexed[$uid] ?? null, $sequence),
            fn($r) => $r !== null
        ));
    }

    /**
     * Finds child categories by parent UID.
     *
     * Ordering priority: orderField > defaultOrderings (sorting ASC).
     *
     * @param int    $parentUid
     * @param bool   $returnRawQueryResult
     * @param string $orderField      DB field to order by; empty = use defaultOrderings
     * @param string $orderDirection  'asc' or 'desc'
     */
    public function findByParent(
        int    $parentUid,
        bool   $returnRawQueryResult = false,
        string $orderField = '',
        string $orderDirection = 'asc',
    ): array|QueryResult {
        $query = $this->createQuery();

        if ($orderField !== '') {
            $direction = strtolower($orderDirection) === 'desc'
                ? QueryInterface::ORDER_DESCENDING
                : QueryInterface::ORDER_ASCENDING;
            $query->setOrderings([
                $orderField => $direction,
                'sorting'   => QueryInterface::ORDER_ASCENDING,
            ]);
        }

        return $query
            ->matching(
                $query->equals('parent', $parentUid)
            )
            ->execute($returnRawQueryResult);
    }
}
