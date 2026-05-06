<?php

namespace Amdeu\MenuControls\Domain\Model;

use TYPO3\CMS\Extbase\Persistence\ObjectStorage;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\Annotation\ORM\Lazy;

/**
 * Model for 'sys_category'
 */
class Category extends AbstractEntity
{
	public string $title = '';

	/**
	 * @var ObjectStorage<Category>|null
	 */
	#[Lazy]
	protected ?ObjectStorage $parent = null;

	public string $slug = '';

	public function getParent(): ?Category
	{
		return $this->parent?->current();
	}
}