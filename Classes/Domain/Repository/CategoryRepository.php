<?php

namespace Amdeu\MenuControls\Domain\Repository;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Extbase\Persistence\Generic\QueryResult;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\Repository;
use TYPO3\CMS\Core\Context\Context;

/**
 * Repository for 'sys_category' records.
 * Provides methods to find categories by UID lists and parent relations.
 */
#[Autoconfigure(public: true)]
class CategoryRepository extends Repository
{
	/**
	 * Default ordering for category queries
	 *
	 * @var array
	 */
	protected $defaultOrderings = [
		'sorting' => \TYPO3\CMS\Extbase\Persistence\QueryInterface::ORDER_ASCENDING
	];

	/**
	 * Initialize the repository.
	 * Sets up query settings to ignore storage page restrictions.
	 */
	public function initializeObject(): void
	{
		// todo: check if language aspect is set
		$querySettings = GeneralUtility::makeInstance(Typo3QuerySettings::class);
		$querySettings->setRespectStoragePage(false);
		$this->setDefaultQuerySettings($querySettings);
	}

	/**
	 * Finds categories by a list of UIDs.
	 * Also checks for localized versions of the categories.
	 *
	 * @param string $uids Comma-separated list of category UIDs
	 * @param bool $returnRawQueryResult Whether to return raw query results (default: false)
	 * @return array|QueryResult The matched categories or null if no UIDs provided
	 */
	public function findByUidList(string $uids, bool $returnRawQueryResult = false): array|QueryResult
	{
		if (!$uids) {
			return [];
		}
		$query = $this->createQuery();
		return $query
			->matching(
				$query->logicalAnd(
					$query->equals('hidden', 0),
					$query->logicalOr(
						$query->in('l10n_parent', explode(',', $uids)),
						$query->in('uid', explode(',', $uids))
					),
				)
			)
			->execute($returnRawQueryResult);
	}

	/**
	 * Finds child categories by parent UID.
	 *
	 * @param int $parentUid UID of the parent category
	 * @param bool $returnRawQueryResult Whether to return raw query results (default: false)
	 * @return array|QueryResult The matched child categories
	 */
	public function findByParent(int $parentUid, bool $returnRawQueryResult = false): array|QueryResult
	{
		$query = $this->createQuery();
		return $query
			->matching(
				$query->logicalAnd(
					$query->equals('hidden', 0),
					$query->equals('parent', $parentUid)
				)
			)
			->execute($returnRawQueryResult);
	}
}