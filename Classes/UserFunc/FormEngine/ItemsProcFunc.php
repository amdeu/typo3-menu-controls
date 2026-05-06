<?php

namespace Amdeu\MenuControls\UserFunc\FormEngine;

class ItemsProcFunc
{

	public function doktypes(&$params): void
	{
		$doktypeSelectItems = $GLOBALS['TCA']['pages']['columns']['doktype']['config']['items'] ?? [];
		$params['items'] = array_filter($doktypeSelectItems, function ($item) {
			$group = $item['group'] ?? $item[3] ?? null;
			return $group !== 'special'; // Exclude items in the "special" group
		});
	}
}