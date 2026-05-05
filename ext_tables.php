<?php

declare(strict_types=1);

use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

ExtensionUtility::registerPlugin(
    'MenuControls',
    'PageMenu',
    'Menu Controls: Page Menu',
    'content-special-menu',
    'menus',
	'Menu of pages with flexible demand, order, pagination and category-based filter.'
);

$GLOBALS['TCA']['tt_content']['types']['tx_menu_controls_pagemenu']['columnsOverrides'] = [
	'pi_flexform' => [
		'config' => [
			'ds' => 'FILE:EXT:menu_controls/Configuration/FlexForms/PageMenu.xml',
		],
	],
];
