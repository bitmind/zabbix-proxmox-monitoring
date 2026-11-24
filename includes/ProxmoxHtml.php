<?php declare(strict_types = 0);

namespace Modules\ProxmoxMonitoring\Includes;

use CCol,
	CDiv,
	CLink,
	CLinkAction,
	CMenuPopupHelper,
	CSeverityHelper,
	CSpan,
	CTag,
	CUrl;

/**
 * Small HTML building blocks shared by the views.
 */
class ProxmoxHtml {

	public static function statusDot(string $status, string $title = ''): CSpan {
		return (new CSpan())
			->addClass('pve-dot')
			->addClass('pve-dot-'.$status)
			->setTitle($title);
	}

	/**
	 * Problem counts by severity, highest first, in the native problem icon style.
	 */
	public static function problemBadges(array $severities, ?CUrl $url = null) {
		krsort($severities, SORT_NUMERIC);
		$badges = [];

		foreach ($severities as $severity => $count) {
			if ($count > 0) {
				$badges[] = (new CSpan($count))
					->addClass(ZBX_STYLE_PROBLEM_ICON_LIST_ITEM)
					->addClass(CSeverityHelper::getStatusStyle((int) $severity))
					->setTitle(CSeverityHelper::getName((int) $severity));
			}
		}

		if (!$badges) {
			return '';
		}

		$list = (new CDiv($badges))->addClass(ZBX_STYLE_PROBLEM_ICON_LIST);

		return $url !== null
			? (new CLink($list, $url))->addClass(ZBX_STYLE_PROBLEM_ICON_LINK)
			: $list;
	}

	/**
	 * Highest severity, for compact badges in the object selector.
	 */
	public static function problemCountBadge(array $severities) {
		if (!$severities) {
			return null;
		}

		$max = max(array_keys($severities));

		return (new CSpan(array_sum($severities)))
			->addClass('pve-count')
			->addClass(CSeverityHelper::getStatusStyle((int) $max))
			->setTitle(_n('%1$s problem', '%1$s problems', array_sum($severities)));
	}

	/**
	 * Dot in the color of the highest severity, without numbers.
	 */
	public static function severityDot(array $severities) {
		if (!$severities) {
			return null;
		}

		$max = (int) max(array_keys($severities));

		return (new CSpan())
			->addClass('pve-sev-dot')
			->addClass('pve-sev-'.$max)
			->setTitle(CSeverityHelper::getName($max));
	}

	/**
	 * Item name with the native item menu (Graph, Values, latest values, configuration).
	 */
	public static function itemLink($label, string $itemid, string $backurl): CLinkAction {
		return (new CLinkAction($label))->setMenuPopup(CMenuPopupHelper::getItem([
			'itemid' => $itemid,
			'context' => 'host',
			'backurl' => $backurl
		]));
	}

	public static function isNumeric(array $item): bool {
		return in_array($item['value_type'], [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]);
	}

	public static function historyUrl(array $itemids, bool $graph): CUrl {
		return (new CUrl('history.php'))
			->setArgument('action', $graph ? HISTORY_GRAPH : HISTORY_VALUES)
			->setArgument('itemids', array_values($itemids));
	}

	/**
	 * Formatted last value of an item, or a dash.
	 */
	public static function value(?array $item, bool $trim = false): string {
		if ($item === null || $item['lastvalue'] === null) {
			return '—';
		}

		return formatHistoryValue($item['lastvalue'], $item, $trim);
	}

	public static function sectionHeader(string $title, array $controls = []): CDiv {
		return (new CDiv([
			(new CTag('h4', true, $title))->addClass('pve-section-title'),
			$controls ? (new CDiv($controls))->addClass('pve-section-controls') : null
		]))->addClass('pve-section-header');
	}

	public static function nowrapCol($content): CCol {
		return (new CCol($content))->addClass(ZBX_STYLE_NOWRAP);
	}
}
