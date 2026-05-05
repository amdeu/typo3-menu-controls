<?php

namespace Amdeu\MenuControls\Components;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3Fluid\Fluid\Core\Component\AbstractComponentCollection;
use TYPO3Fluid\Fluid\View\TemplatePaths;

final class ComponentCollection extends AbstractComponentCollection
{
	public function getTemplatePaths(): TemplatePaths
	{
		$templatePaths = new TemplatePaths();
		$templatePaths->setTemplateRootPaths([
			ExtensionManagementUtility::extPath('menu_controls', 'Resources/Private/Components/')
		]);
		return $templatePaths;
	}
}