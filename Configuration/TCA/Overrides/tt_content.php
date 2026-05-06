<?php

declare(strict_types=1);

use TYPO3\CMS\Extbase\Utility\ExtensionUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionUtility::registerPlugin(
	'MenuControls',
	'PageMenu',
	'Menu Controls: Page Menu',
	'content-special-menu',
	'menu',
	'Menu of pages with flexible page selection, order, pagination and category-based filter.',
	'FILE:EXT:menu_controls/Configuration/FlexForms/PageMenu.xml'
);

ExtensionManagementUtility::addToAllTCAtypes(
	'tt_content',
	'pi_flexform',
	'menucontrols_pagemenu',
	'after:subheader'
);
