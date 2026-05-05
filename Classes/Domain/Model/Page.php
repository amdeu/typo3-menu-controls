<?php

namespace Amdeu\MenuControls\Domain\Model;

/**
 * Minimal Extbase model for 'pages' records.
 * Used only to satisfy the Extbase repository requirement.
 */
class Page extends AbstractEntity
{
	public string $title = '';
	public string $slug = '';
	public int $doktype = 1;
}