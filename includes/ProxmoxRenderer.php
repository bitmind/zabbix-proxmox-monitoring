<?php declare(strict_types = 0);

namespace Modules\ProxmoxMonitoring\Includes;

use CButtonIcon,
	CCheckBox,
	CCol,
	CDiv,
	CLink,
	CRoleHelper,
	CRow,
	CSeverityHelper,
	CSpan,
	CTableInfo,
	CTag,
	CUrl,
	CWebUser;

/**
 * Renders the object selector and the three bands of the detail page: widgets, problems and latest data.
 */
class ProxmoxRenderer {

	public const TAB_GENERAL = 'general';


	/**
	 * Widgets shown for each object type.
	 *   value: last value of an item;  values: last values of several items;
	 *   gauge: used/total;  pgauge: an item that is already a percentage.
	 */
	private static function widgetSpec(string $type): array {
		switch ($type) {
			case ProxmoxData::TYPE_CLUSTER:
				return [
					['status', _('Quorum')],
					['nodes', _('Nodes online')],
					['guests', _('LXC running'), ProxmoxData::TYPE_LXC],
					['guests', _('VMs running'), ProxmoxData::TYPE_QEMU],
					['count', _('Storages'), ProxmoxData::TYPE_STORAGE]
				];

			case ProxmoxData::TYPE_NODE:
				return [
					['status', _('Status')],
					['version', _('PVE version'), 'pveversion'],
					['value', _('Kernel'), 'kernelversion'],
					['value', _('Uptime'), 'uptime'],
					['value', _('Time zone'), 'timezone'],
					['guests', _('LXC running'), ProxmoxData::TYPE_LXC],
					['guests', _('VMs running'), ProxmoxData::TYPE_QEMU],
					['value', _('Load average'), 'loadavg'],
					['values', _('Network'), [['netin', '↓'], ['netout', '↑']]],
					['pgauge', _('CPU'), 'cpu', 'iowait'],
					['gauge', _('Memory'), 'memused', 'memtotal'],
					['gauge', _('Root filesystem'), 'rootused', 'roottotal'],
					['gauge', _('Swap'), 'swapused', 'swaptotal']
				];

			case ProxmoxData::TYPE_STORAGE:
				return [
					['node', _('Node')],
					['value', _('Type'), 'plugintype'],
					['value', _('Content'), 'content'],
					['value', _('Size'), 'maxdisk'],
					['gauge', _('Space'), 'disk', 'maxdisk']
				];

			case ProxmoxData::TYPE_LXC:
				return [
					['status', _('Status')],
					['node', _('Node')],
					['vmid', _('CT ID')],
					['value', _('Uptime'), 'uptime'],
					['values', _('Network'), [['netin', '↓'], ['netout', '↑']]],
					['values', _('Disk I/O'), [['diskread', _('R')], ['diskwrite', _('W')]]],
					['pgauge', _('CPU'), 'cpu'],
					['gauge', _('Memory'), 'mem', 'maxmem'],
					['gauge', _('Disk space'), 'disk', 'maxdisk']
				];

			case ProxmoxData::TYPE_QEMU:
				return [
					['status', _('Status')],
					['node', _('Node')],
					['vmid', _('VM ID')],
					['value', _('Uptime'), 'uptime'],
					['values', _('Network'), [['netin', '↓'], ['netout', '↑']]],
					['values', _('Disk I/O'), [['diskread', _('R')], ['diskwrite', _('W')]]],
					['pgauge', _('CPU'), 'cpu'],
					['gauge', _('Memory'), 'mem', 'maxmem']
				];
		}

		return [];
	}

	/*
	 * Object selector.
	 */

	public static function selector(array $data, string $type, ?string $selected, string $hostid): CDiv {
		if ($type === ProxmoxData::TYPE_STORAGE) {
			return self::storageTable($data, $selected, $hostid);
		}

		$objects = $data['objects'][$type];
		$grouped = in_array($type, [ProxmoxData::TYPE_STORAGE, ProxmoxData::TYPE_LXC, ProxmoxData::TYPE_QEMU]);
		$list = new CTag('ul', true);
		$last_node = null;

		foreach ($objects as $id => $object) {
			if ($grouped && $object['node'] !== $last_node) {
				$last_node = $object['node'];
				$list->addItem((new CTag('li', true, $last_node !== '' ? $last_node : _('Unknown node')))
					->addClass('pve-nav-group')
				);
			}

			$url = (new CUrl('zabbix.php'))
				->setArgument('action', 'proxmox.view')
				->setArgument('hostid', $hostid)
				->setArgument('tab', $type)
				->setArgument('object', (string) $id);

			$link = (new CLink([
				ProxmoxHtml::statusDot($object['status'], $object['status_text']),
				(new CSpan($object['name']))->addClass('pve-nav-name'),
				ProxmoxHtml::problemCountBadge($object['problems'])
			], $url->getUrl()))
				->addClass('pve-nav-link')
				->setAttribute('data-object', (string) $id)
				->setAttribute('data-search', mb_strtolower($object['name'].' '.$object['vmid'].' '.$object['node']));

			$list->addItem((new CTag('li', true, $link))
				->addClass('pve-nav-item')
				->addClass((string) $id === $selected ? 'pve-nav-selected' : null)
			);
		}

		if (!$objects) {
			$list->addItem((new CTag('li', true, _('No data found')))->addClass('pve-nav-empty'));
		}

		return (new CDiv($list))->addClass('pve-nav-list');
	}

	private static function objectUrl(string $hostid, string $type, string $id): CUrl {
		return (new CUrl('zabbix.php'))
			->setArgument('action', 'proxmox.view')
			->setArgument('hostid', $hostid)
			->setArgument('tab', $type)
			->setArgument('object', $id);
	}

	/**
	 * Storages as a table: the first column works like the selector, the others show usage and configuration.
	 */
	private static function storageTable(array $data, ?string $selected, string $hostid): CDiv {
		$items = $data['items'];
		$table = (new CTableInfo())
			->setHeader([_('Name'), _('Problems'), _('Usage'), _('Size'), _('Type'), _('Content')])
			->addClass('pve-storage-table');

		$value = static function (array $object, string $metric) use ($items): ?array {
			return array_key_exists($metric, $object['metrics']) ? $items[$object['metrics'][$metric]] : null;
		};

		$last_node = null;

		foreach ($data['objects'][ProxmoxData::TYPE_STORAGE] as $id => $object) {
			if ($object['node'] !== $last_node) {
				$last_node = $object['node'];
				$table->addRow(
					(new CRow((new CCol($last_node !== '' ? $last_node : _('Unknown node')))->setColSpan(6)))
						->addClass('pve-nav-group')
				);
			}

			$used = $value($object, 'disk');
			$total = $value($object, 'maxdisk');
			$pct = ($used !== null && $total !== null)
				? ProxmoxData::percent($used['lastvalue'], $total['lastvalue'])
				: null;

			$link = (new CLink([
				ProxmoxHtml::statusDot($object['status'], $object['status_text']),
				(new CSpan($object['name']))->addClass('pve-nav-name')
			], self::objectUrl($hostid, ProxmoxData::TYPE_STORAGE, (string) $id)->getUrl()))
				->addClass('pve-nav-link')
				->setAttribute('data-object', (string) $id)
				->setAttribute('data-search', mb_strtolower($object['name'].' '.$object['node']));

			$table->addRow(
				(new CRow([
					$link,
					ProxmoxHtml::problemBadges($object['problems']),
					$pct === null ? '—' : self::usageBar($pct),
					$total !== null ? ProxmoxHtml::value($total) : '—',
					ProxmoxHtml::value($value($object, 'plugintype')),
					(new CCol(ProxmoxHtml::value($value($object, 'content'))))->addClass(ZBX_STYLE_WORDBREAK)
				]))
					->addClass('pve-nav-item')
					->addClass((string) $id === $selected ? 'pve-nav-selected' : null)
			);
		}

		return (new CDiv($table))->addClass('pve-nav-list');
	}

	private static function usageBar(float $pct): CDiv {
		$pct = max(0, min(100, $pct));

		return (new CDiv([
			(new CDiv(
				(new CDiv())
					->addClass('pve-bar-fill')
					->setAttribute('style', 'width: '.number_format($pct, 1, '.', '').'%')
			))->addClass('pve-bar'),
			(new CSpan(round($pct, 1).' %'))->addClass('pve-bar-label')
		]))
			->addClass('pve-usage')
			->setAttribute('data-tone', self::tone($pct));
	}

	private static function tone(?float $pct): string {
		if ($pct === null) {
			return 'unknown';
		}

		return $pct >= 90 ? 'critical' : ($pct >= 75 ? 'warning' : 'normal');
	}

	/*
	 * Panel with the three bands.
	 */

	/**
	 * @param array  $data      ProxmoxData::loadHost() result.
	 * @param array  $problems  ProxmoxData::getProblems() result with all active problems.
	 * @param string $type      Object type or TAB_GENERAL.
	 * @param array  $options   'show_ack', 'show_suppressed', 'backurl'.
	 */
	public static function panel(array $data, array $problems, string $type, ?string $id, array $options): CDiv {
		$widgets = null;

		if ($type === self::TAB_GENERAL) {
			$object = null;
			$title = $data['host']['name'];
			$widgets = self::generalWidgets($data);
			$itemids = $data['general_itemids'];
		}
		else {
			if ($id === null || !array_key_exists($id, $data['objects'][$type])) {
				return (new CDiv(
					(new CDiv($data['objects'][$type] ? _('Select an object on the left.') : _('No data found')))
						->addClass('pve-empty')
				))->addClass('pve-panel');
			}

			$object = $data['objects'][$type][$id];
			$problems = ProxmoxData::problemsOfObject($problems, $type, $id);
			$title = $object['name'];
			$itemids = $object['itemids'];

			// Storages show usage and configuration in the table above.
			if ($type !== ProxmoxData::TYPE_STORAGE) {
				$widgets = self::objectWidgets($data, $object);
			}
		}

		$ack_count = 0;
		$suppressed_count = 0;

		foreach ($problems as $problem) {
			$ack_count += ($problem['acknowledged'] == EVENT_ACKNOWLEDGED) ? 1 : 0;
			$suppressed_count += ($problem['suppressed'] == ZBX_PROBLEM_SUPPRESSED_TRUE) ? 1 : 0;
		}

		$problems = array_values(array_filter($problems, static function (array $problem) use ($options): bool {
			return ($options['show_ack'] || $problem['acknowledged'] != EVENT_ACKNOWLEDGED)
				&& ($options['show_suppressed'] || $problem['suppressed'] != ZBX_PROBLEM_SUPPRESSED_TRUE);
		}));

		$heading = [(new CDiv($title))->addClass('pve-panel-title')];

		if ($object !== null) {
			$heading[] = (new CSpan([
				ProxmoxHtml::statusDot($object['status']),
				$object['status_text']
			]))->addClass('pve-panel-status');

			if ($object['node'] !== '' && $type !== ProxmoxData::TYPE_NODE) {
				$heading[] = (new CSpan(_s('on %1$s', $object['node'])))->addClass('pve-panel-subtitle');
			}
		}

		return (new CDiv([
			(new CDiv($heading))->addClass('pve-panel-heading'),
			$widgets !== null
				? (new CDiv([ProxmoxHtml::sectionHeader(_('Overview')), $widgets]))
					->addClass('pve-band')
					->addClass('pve-band-widgets')
				: null,
			(new CDiv([
				ProxmoxHtml::sectionHeader(_('Problems'), [
					(new CSpan([
						(new CCheckBox('pve_show_ack'))
							->setLabel(_('Show acknowledged'))
							->setChecked($options['show_ack'])
							->addClass('pve-show-ack'),
						(new CSpan($ack_count))->addClass('pve-tab-count')
					]))->addClass('pve-check'),
					(new CSpan([
						(new CCheckBox('pve_show_suppressed'))
							->setLabel(_('Show suppressed'))
							->setChecked($options['show_suppressed'])
							->addClass('pve-show-suppressed'),
						(new CSpan($suppressed_count))->addClass('pve-tab-count')
					]))->addClass('pve-check')
				]),
				self::problemsTable($problems, $options['show_suppressed'])
			]))->addClass('pve-band')->addClass('pve-band-problems'),
			(new CDiv([
				ProxmoxHtml::sectionHeader(_('Latest data')),
				self::latestTable($data['items'], $itemids, $options['backurl'], $object !== null)
			]))->addClass('pve-band')->addClass('pve-band-latest')
		]))
			->addClass('pve-panel')
			->setAttribute('data-object', $id ?? '');
	}

	private static function generalWidgets(array $data): CDiv {
		$items_by_key = [];

		foreach ($data['general_itemids'] as $itemid) {
			$items_by_key[$data['items'][$itemid]['key_']] = $data['items'][$itemid];
		}

		$cards = [];

		$api = $items_by_key[ProxmoxData::KEY_API] ?? null;

		if ($api === null || $api['lastvalue'] === null) {
			$cards[] = self::card(_('API'), [ProxmoxHtml::statusDot(ProxmoxData::STATUS_UNKNOWN), _('Unknown')]);
		}
		elseif ((int) $api['lastvalue'] == 200) {
			$cards[] = self::card(_('API'), [ProxmoxHtml::statusDot(ProxmoxData::STATUS_OK), _('Available')]);
		}
		else {
			$cards[] = self::card(_('API'), [ProxmoxHtml::statusDot(ProxmoxData::STATUS_PROBLEM),
				_s('Unavailable (%1$s)', $api['lastvalue'])
			]);
		}

		$versions = [];

		foreach ($data['objects'][ProxmoxData::TYPE_NODE] as $node) {
			if (array_key_exists('pveversion', $node['metrics'])) {
				$value = $data['items'][$node['metrics']['pveversion']]['lastvalue'];

				if ($value !== null && $value !== '') {
					$versions[ProxmoxData::shortVersion($value)] = true;
				}
			}
		}

		$versions = array_keys($versions);
		natsort($versions);
		$cards[] = self::card(_('PVE version'), $versions ? implode(', ', $versions) : '—');

		$clusters = $data['objects'][ProxmoxData::TYPE_CLUSTER];
		$cards[] = self::card(_('Cluster'), $clusters
			? array_map(static function (array $cluster): CSpan {
				return (new CSpan([ProxmoxHtml::statusDot($cluster['status'], $cluster['status_text']), $cluster['name']]))
					->addClass('pve-inline');
			}, array_values($clusters))
			: (new CSpan(_('Standalone')))->addClass(ZBX_STYLE_GREY)
		);

		$counters = [];

		foreach (ProxmoxData::typeLabels() as $type => $label) {
			$objects = $data['objects'][$type];
			$total = count($objects);

			switch ($type) {
				case ProxmoxData::TYPE_NODE:
					$sub = _s('%1$s online', self::countStatus($objects, ProxmoxData::STATUS_OK));
					break;

				case ProxmoxData::TYPE_LXC:
				case ProxmoxData::TYPE_QEMU:
					$sub = _s('%1$s running', self::countStatus($objects, ProxmoxData::STATUS_OK));
					break;

				case ProxmoxData::TYPE_STORAGE:
					$warn = self::countStatus($objects, ProxmoxData::STATUS_WARNING);
					$sub = $warn ? _n('%1$s almost full', '%1$s almost full', $warn) : null;
					break;

				default:
					$sub = null;
			}

			$counters[] = (new CDiv([
				(new CDiv($total))->addClass('pve-counter-value'),
				(new CDiv($label))->addClass('pve-counter-label'),
				$sub !== null ? (new CDiv($sub))->addClass('pve-counter-sub') : null
			]))
				->addClass('pve-counter')
				->setAttribute('data-tab', $type);
		}

		return (new CDiv([
			(new CDiv($cards))->addClass('pve-cards'),
			(new CDiv($counters))->addClass('pve-counters')
		]))->addClass('pve-widgets');
	}

	private static function countStatus(array $objects, string $status): int {
		return count(array_filter($objects, static function (array $object) use ($status): bool {
			return $object['status'] === $status;
		}));
	}

	private static function objectWidgets(array $data, array $object): CDiv {
		$tiles = [];
		$visuals = [];
		$items = $data['items'];
		$item = static function (?string $metric) use ($object, $items): ?array {
			return ($metric !== null && array_key_exists($metric, $object['metrics']))
				? $items[$object['metrics'][$metric]]
				: null;
		};

		$spec = self::widgetSpec($object['type']);
		$stopped = $object['status'] === ProxmoxData::STATUS_OFF;

		foreach ($spec as $widget) {
			[$kind, $label] = $widget;

			switch ($kind) {
				case 'status':
					$tiles[] = self::card($label, [ProxmoxHtml::statusDot($object['status']), $object['status_text']]);
					break;

				case 'node':
					$tiles[] = self::card($label, $object['node'] !== '' ? $object['node'] : '—');
					break;

				case 'vmid':
					$tiles[] = self::card($label, $object['vmid']);
					break;

				case 'value':
				case 'version':
					$it = $item($widget[2]);

					if ($it === null) {
						break;
					}

					$value = ProxmoxHtml::value($it);

					if ($kind === 'version' && $it['lastvalue'] !== null) {
						$value = (new CSpan(ProxmoxData::shortVersion($it['lastvalue'])))->setTitle($it['lastvalue']);
					}

					$tiles[] = self::card($label, $value, $it);
					break;

				case 'count':
					$tiles[] = self::card($label, count($data['objects'][$widget[2]]));
					break;

				case 'nodes':
					$nodes = $data['objects'][ProxmoxData::TYPE_NODE];
					$tiles[] = self::card($label, self::ratio(self::countStatus($nodes, ProxmoxData::STATUS_OK),
						count($nodes)
					));
					break;

				case 'guests':
					$guests = $data['objects'][$widget[2]];

					if ($object['type'] === ProxmoxData::TYPE_NODE) {
						$guests = array_filter($guests, static function (array $guest) use ($object): bool {
							return $guest['node'] === $object['id'];
						});
					}

					$tiles[] = self::card($label, self::ratio(self::countStatus($guests, ProxmoxData::STATUS_OK),
						count($guests)
					));
					break;

				case 'gauge':
					$used = $item($widget[2]);
					$total = $item($widget[3]);

					if ($used !== null && $total !== null) {
						$visuals[] = self::gauge($label,
							$stopped ? null : ProxmoxData::percent($used['lastvalue'], $total['lastvalue']),
							[ProxmoxHtml::value($used), (new CSpan(' / '))->addClass('pve-muted'), ProxmoxHtml::value($total)],
							[$used['itemid'], $total['itemid']]
						);
					}
					break;

				case 'pgauge':
					$it = $item($widget[2]);

					if ($it !== null) {
						$pct = ($stopped || !is_numeric($it['lastvalue'])) ? null : (float) $it['lastvalue'];
						$detail_item = $item($widget[3] ?? null);
						$detail = $detail_item !== null
							? [(new CSpan(self::shortItemName($detail_item['name']).': '))->addClass('pve-muted'),
								ProxmoxHtml::value($detail_item)
							]
							: ProxmoxHtml::value($it);

						$visuals[] = self::gauge($label, $pct, $detail,
							array_column(array_filter([$it, $detail_item]), 'itemid')
						);
					}
					break;

				case 'values':
					$values = [];

					foreach ($widget[2] as [$metric, $prefix]) {
						$it = $item($metric);

						if ($it === null) {
							continue;
						}

						$values[] = (new CLink([
							(new CSpan($prefix))->addClass('pve-muted'),
							' ',
							ProxmoxHtml::value($it)
						], ProxmoxHtml::historyUrl([$it['itemid']], ProxmoxHtml::isNumeric($it))))
							->addClass('pve-value-link')
							->setTitle($it['name']);
					}

					if ($values) {
						$tiles[] = self::card($label, (new CDiv($values))->addClass('pve-card-values'));
					}
					break;
			}
		}

		return (new CDiv([
			(new CDiv($tiles))->addClass('pve-cards'),
			$visuals ? (new CDiv($visuals))->addClass('pve-visuals') : null
		]))->addClass('pve-widgets');
	}

	private static function ratio(int $part, int $total): array {
		return [
			(new CSpan($part))->addClass('pve-ratio-part'),
			(new CSpan("\u{00a0}/\u{00a0}".$total))->addClass('pve-muted')
		];
	}

	private static function card(string $label, $value, ?array $item = null): CDiv {
		if ($item !== null && $item['lastvalue'] !== null) {
			$value = (new CLink($value, ProxmoxHtml::historyUrl([$item['itemid']], ProxmoxHtml::isNumeric($item))))
				->addClass('pve-value-link')
				->setTitle($item['name']);
		}

		return (new CDiv([
			(new CDiv($label))->addClass('pve-card-label'),
			(new CDiv($value))->addClass('pve-card-value')
		]))->addClass('pve-card');
	}

	/**
	 * Small semicircular gauge.
	 *
	 * @param float|null $pct      Percentage, null if unknown.
	 * @param mixed      $detail   Text under the gauge.
	 * @param array      $itemids  Items opened by the label link.
	 */
	private static function gauge(string $label, ?float $pct, $detail, array $itemids): CDiv {
		$arc_length = 125.66;
		$fill = $pct === null ? 0 : max(0, min(100, $pct)) / 100 * $arc_length;

		$svg = '<svg viewBox="0 0 100 56" class="pve-gauge-svg" aria-hidden="true">'.
			'<path class="pve-gauge-track" d="M 10 50 A 40 40 0 0 1 90 50" />'.
			'<path class="pve-gauge-fill" d="M 10 50 A 40 40 0 0 1 90 50" stroke-dasharray="'.
				number_format($fill, 2, '.', '').' '.$arc_length.'" />'.
			'<text class="pve-gauge-text" x="50" y="47" text-anchor="middle">'.
				($pct === null ? '—' : round($pct).'%').'</text>'.
			'</svg>';

		return (new CDiv([
			(new CDiv(new CLink($label, ProxmoxHtml::historyUrl($itemids, true))))->addClass('pve-visual-label'),
			(new CDiv(new \CJsScript($svg)))->addClass('pve-gauge-body'),
			(new CDiv($detail))
				->addClass('pve-visual-detail')
				->setTitle($pct === null ? '' : round($pct, 2).' %')
		]))
			->addClass('pve-visual')
			->addClass('pve-gauge')
			->setAttribute('data-tone', self::tone($pct));
	}

	private static function problemsTable(array $problems, bool $show_suppressed): CTableInfo {
		$table = (new CTableInfo())
			->setHeader([
				(new CCol(_('Time')))->addClass(ZBX_STYLE_RIGHT),
				_('Severity'),
				_('Info'),
				_('Problem'),
				_('Duration'),
				_('Ack'),
				_('Update'),
				_('Tags')
			])
			->addClass('pve-problems')
			->setNoDataMessage(_('No problems'));

		if (!$problems) {
			return $table;
		}

		if ($show_suppressed) {
			\CScreenProblem::addSuppressionNames($problems);
		}

		$tags = makeTags($problems, true, 'eventid', ZBX_TAG_COUNT_DEFAULT);
		$today = strtotime('today');
		$can_update = CWebUser::checkAccess(CRoleHelper::ACTIONS_ACKNOWLEDGE_PROBLEMS)
			|| CWebUser::checkAccess(CRoleHelper::ACTIONS_ADD_PROBLEM_COMMENTS)
			|| CWebUser::checkAccess(CRoleHelper::ACTIONS_CHANGE_SEVERITY)
			|| CWebUser::checkAccess(CRoleHelper::ACTIONS_SUPPRESS_PROBLEMS)
			|| CWebUser::checkAccess(CRoleHelper::ACTIONS_CLOSE_PROBLEMS);

		foreach ($problems as $problem) {
			$clock = ($problem['clock'] >= $today)
				? zbx_date2str(TIME_FORMAT_SECONDS, $problem['clock'])
				: zbx_date2str(DATE_TIME_FORMAT_SECONDS, $problem['clock']);

			$info = [];

			if ($problem['suppression_data']) {
				$info[] = makeSuppressedProblemIcon($problem['suppression_data'], false);
			}

			$is_ack = $problem['acknowledged'] == EVENT_ACKNOWLEDGED;

			$update = $can_update
				? (new CLink(_('Update')))
					->addClass(ZBX_STYLE_LINK_ALT)
					->setAttribute('data-eventid', $problem['eventid'])
					->onClick('acknowledgePopUp({eventids: [this.dataset.eventid]}, this);')
				: new CSpan(_('Update'));

			$name = $problem['name'];

			if ($problem['opdata'] !== '') {
				$name = [$name, ' ', (new CSpan('('.$problem['opdata'].')'))->addClass(ZBX_STYLE_GREY)];
			}

			$table->addRow([
				(new CCol(new CLink($clock,
					(new CUrl('tr_events.php'))
						->setArgument('triggerid', $problem['objectid'])
						->setArgument('eventid', $problem['eventid'])
				)))
					->addClass(ZBX_STYLE_NOWRAP)
					->addClass(ZBX_STYLE_RIGHT),
				CSeverityHelper::makeSeverityCell((int) $problem['severity']),
				makeInformationList($info),
				(new CCol($name))->addClass(ZBX_STYLE_WORDBREAK),
				ProxmoxHtml::nowrapCol(zbx_date2age($problem['clock'])),
				(new CSpan($is_ack ? _('Yes') : _('No')))->addClass($is_ack ? ZBX_STYLE_GREEN : ZBX_STYLE_RED),
				$update,
				(new CDiv($tags[$problem['eventid']] ?? []))->addClass(ZBX_STYLE_TAGS_WRAPPER)
			]);
		}

		return $table;
	}

	private static function latestTable(array $items, array $itemids, string $backurl, bool $strip_names): CTableInfo {
		$table = (new CTableInfo())
			->setHeader([
				_('Name'),
				_('Last value'),
				_('Change'),
				_('Last check'),
				_('Info'),
				''
			])
			->addClass('pve-latest');

		$rows = array_intersect_key($items, array_flip($itemids));
		uasort($rows, static function (array $a, array $b): int {
			return strnatcasecmp($a['name'], $b['name']);
		});

		foreach ($rows as $itemid => $item) {
			$is_graph = ProxmoxHtml::isNumeric($item);
			$name = $strip_names ? self::shortItemName($item['name']) : $item['name'];

			$name_col = [ProxmoxHtml::itemLink($name, (string) $itemid, $backurl)->setTitle($item['name'])];

			if ($item['description_expanded'] !== '') {
				$name_col[] = makeDescriptionIcon($item['description_expanded']);
			}

			$last = $item['history_values'][0] ?? null;
			$prev = $item['history_values'][1] ?? null;

			if ($last !== null) {
				$last_check = (new CSpan(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $last['clock'])))
					->setTitle(zbx_date2age($last['clock']));

				$last_value = (new CSpan(formatHistoryValue($last['value'], $item, true)))
					->addClass(ZBX_STYLE_CURSOR_POINTER)
					->setHint(
						(new CDiv(mb_substr($last['value'], 0, ZBX_HINTBOX_CONTENT_LIMIT)))
							->addClass(ZBX_STYLE_HINTBOX_RAW_DATA)
							->addClass(ZBX_STYLE_HINTBOX_WRAP),
						'', true, '', 0
					);

				$change = '';

				if ($prev !== null && $is_graph) {
					$diff = $last['value'] - $prev['value'];

					if ($diff != 0) {
						$change = ($diff > 0 ? '+' : '').convertUnits([
							'value' => $diff,
							'units' => $item['units'] === 'unixtime' ? 'uptime' : $item['units']
						]);
					}
				}
			}
			else {
				$last_check = '';
				$last_value = '';
				$change = '';
			}

			$info = [];

			if ($item['status'] == ITEM_STATUS_ACTIVE && $item['state'] == ITEM_STATE_NOTSUPPORTED) {
				$info[] = makeErrorIcon($item['error']);
			}

			if ($item['status'] != ITEM_STATUS_ACTIVE) {
				$name_col[] = ' ';
				$name_col[] = (new CSpan(_('Disabled')))->addClass(ZBX_STYLE_RED);
			}

			$has_history = $item['history'] !== '0' && $item['history'] !== '0s';
			$has_trends = $is_graph && $item['trends'] !== '0' && $item['trends'] !== '0s';

			$action = ($has_history || $has_trends)
				? new CLink($is_graph ? _('Graph') : _('History'), ProxmoxHtml::historyUrl([$itemid], $is_graph))
				: '';

			$table->addRow([
				(new CCol($name_col))->addClass(ZBX_STYLE_WORDBREAK),
				(new CCol($last_value))->addClass(ZBX_STYLE_NOWRAP),
				ProxmoxHtml::nowrapCol($change),
				ProxmoxHtml::nowrapCol($last_check),
				makeInformationList($info),
				ProxmoxHtml::nowrapCol($action)
			]);
		}

		return $table;
	}

	/**
	 * "LXC [pve1/web (101)]: CPU usage" → "CPU usage".
	 */
	private static function shortItemName(string $name): string {
		$short = preg_replace('/^(Cluster|Node|Storage|LXC|VM) \[.*?\]:\s+/', '', $name);

		return ($short === null || $short === '') ? $name : $short;
	}
}
