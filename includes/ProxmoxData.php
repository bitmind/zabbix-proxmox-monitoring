<?php declare(strict_types = 0);

namespace Modules\ProxmoxMonitoring\Includes;

use API,
	CArrayHelper,
	CItemKey,
	CMacrosResolverHelper,
	CSettingsHelper,
	Manager;

/**
 * Reads the data collected by the template "Proxmox VE by HTTP" and groups it into Proxmox objects.
 *
 * Items of the template (keys proxmox.*) are assigned to an object by their tags (key parameters as fallback):
 *   cluster=<name>                    → cluster
 *   lxc=<vmid>                        → LXC container
 *   qemu=<vmid>                       → QEMU virtual machine
 *   node=<node> and name=<storage>    → storage
 *   node=<node>                       → node
 * Problems inherit the item tags, so the same rules assign problems to objects.
 */
class ProxmoxData {

	public const TYPE_CLUSTER = 'cluster';
	public const TYPE_NODE = 'node';
	public const TYPE_STORAGE = 'storage';
	public const TYPE_LXC = 'lxc';
	public const TYPE_QEMU = 'qemu';

	public const TYPES = [self::TYPE_CLUSTER, self::TYPE_NODE, self::TYPE_STORAGE, self::TYPE_LXC, self::TYPE_QEMU];

	/** Item that identifies a host monitored by the Proxmox template. */
	public const KEY_API = 'proxmox.api.available';

	/** Only items of the Proxmox template are shown; the host may have other templates too. */
	public const KEY_PREFIX = 'proxmox.';

	public const STATUS_OK = 'ok';
	public const STATUS_PROBLEM = 'problem';
	public const STATUS_WARNING = 'warning';
	public const STATUS_OFF = 'off';
	public const STATUS_UNKNOWN = 'unknown';

	public static function typeLabels(): array {
		return [
			self::TYPE_CLUSTER => _('Clusters'),
			self::TYPE_NODE => _('Nodes'),
			self::TYPE_STORAGE => _('Storages'),
			self::TYPE_LXC => _('LXC'),
			self::TYPE_QEMU => _('QEMU')
		];
	}

	/**
	 * Hosts having the item proxmox.api.available, so also clones of the template and nested linkage are found.
	 *
	 * @return array  Hosts by host ID.
	 */
	public static function getProxmoxHosts(array $filter = []): array {
		$items = API::Item()->get([
			'output' => ['hostid'],
			'groupids' => $filter['groupids'] ?? null,
			'templated' => false,
			'webitems' => false,
			'filter' => ['key_' => self::KEY_API]
		]);

		if (!$items) {
			return [];
		}

		$options = [
			'output' => ['hostid', 'host', 'name', 'status', 'maintenance_status', 'maintenanceid', 'maintenance_type',
				'description'
			],
			'hostids' => array_column($items, 'hostid'),
			'preservekeys' => true
		];

		if (($filter['name'] ?? '') !== '') {
			$options['search'] = ['name' => $filter['name']];
		}

		$hosts = API::Host()->get($options);
		CArrayHelper::sort($hosts, ['name']);

		return $hosts;
	}

	/**
	 * Summary of each Proxmox host, for the list page.
	 */
	public static function getSummaries(array $hosts): array {
		$summaries = [];

		foreach ($hosts as $hostid => $host) {
			$summaries[$hostid] = [
				'api_status' => null,
				'api_itemid' => null,
				'clusters' => [],
				'nodes' => 0,
				'nodes_online' => 0,
				'storages' => 0,
				'lxc' => 0,
				'lxc_running' => 0,
				'qemu' => 0,
				'qemu_running' => 0,
				'versions' => [],
				'problems' => []
			];
		}

		if (!$hosts) {
			return $summaries;
		}

		$items = API::Item()->get([
			'output' => ['itemid', 'hostid', 'key_', 'value_type', 'status', 'state'],
			'selectTags' => ['tag', 'value'],
			'hostids' => array_keys($hosts),
			'webitems' => false,
			'search' => ['key_' => [
				self::KEY_API, 'proxmox.cluster.quorate[', 'proxmox.node.online[', 'proxmox.node.pveversion[',
				'proxmox.node.maxdisk[', 'proxmox.lxc.vmstatus[', 'proxmox.qemu.vmstatus['
			]],
			'startSearch' => true,
			'searchByAny' => true,
			'preservekeys' => true
		]);

		$values = self::getLastValues($items, 1);

		foreach ($items as $itemid => $item) {
			$s = &$summaries[$item['hostid']];
			$value = array_key_exists($itemid, $values) ? $values[$itemid][0]['value'] : null;
			$disabled = $item['status'] != ITEM_STATUS_ACTIVE;

			if ($item['key_'] === self::KEY_API) {
				$s['api_status'] = $value;
				$s['api_itemid'] = $itemid;
				unset($s);
				continue;
			}

			if ($disabled) {
				unset($s);
				continue;
			}

			[$type, $id] = self::classifyItem($item) ?? [null, null];
			$metric = self::metricOf($item['key_']);

			switch ($type) {
				case self::TYPE_CLUSTER:
					$s['clusters'][$id] = ['name' => $id, 'quorate' => $value];
					break;

				case self::TYPE_NODE:
					if ($metric === 'online') {
						$s['nodes']++;
						$s['nodes_online'] += ($value !== null && (int) $value == 1) ? 1 : 0;
					}
					elseif ($metric === 'pveversion' && $value !== null && $value !== '') {
						$version = self::shortVersion($value);
						$s['versions'][$version] = $version;
					}
					break;

				case self::TYPE_STORAGE:
					$s['storages']++;
					break;

				case self::TYPE_LXC:
				case self::TYPE_QEMU:
					$s[$type]++;
					$s[$type.'_running'] += ($value === 'running') ? 1 : 0;
					break;
			}

			unset($s);
		}

		$problems = API::Problem()->get([
			'output' => ['eventid', 'objectid', 'severity'],
			'hostids' => array_keys($hosts),
			'acknowledged' => false,
			'suppressed' => false,
			'symptom' => false
		]);

		if ($problems) {
			$triggers = API::Trigger()->get([
				'output' => [],
				'selectHosts' => ['hostid'],
				'selectItems' => ['key_'],
				'triggerids' => array_unique(array_column($problems, 'objectid')),
				'preservekeys' => true
			]);

			foreach ($problems as $problem) {
				if (!array_key_exists($problem['objectid'], $triggers)) {
					continue;
				}

				$keys = array_column($triggers[$problem['objectid']]['items'], 'key_');
				$is_proxmox = (bool) array_filter($keys, static function (string $key): bool {
					return strpos($key, self::KEY_PREFIX) === 0;
				});

				if (!$is_proxmox) {
					continue;
				}

				foreach ($triggers[$problem['objectid']]['hosts'] as $host) {
					if (array_key_exists($host['hostid'], $summaries)) {
						$severity = (int) $problem['severity'];
						$summaries[$host['hostid']]['problems'][$severity] =
							($summaries[$host['hostid']]['problems'][$severity] ?? 0) + 1;
					}
				}
			}
		}

		foreach ($summaries as &$s) {
			natsort($s['versions']);
			$s['versions'] = array_values($s['versions']);
		}
		unset($s);

		return $summaries;
	}

	/**
	 * Load all the items of a Proxmox host and group them into objects.
	 *
	 * @return array|null
	 */
	public static function loadHost(string $hostid): ?array {
		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name', 'status', 'maintenance_status', 'maintenanceid', 'maintenance_type',
				'description'
			],
			'hostids' => [$hostid]
		]);

		if (!$hosts) {
			return null;
		}

		$items = API::Item()->get([
			'output' => ['itemid', 'hostid', 'type', 'name_resolved', 'key_', 'flags', 'delay', 'history', 'trends',
				'status', 'value_type', 'units', 'description', 'state', 'error'
			],
			'selectTags' => ['tag', 'value'],
			'selectValueMap' => ['mappings'],
			'hostids' => [$hostid],
			'webitems' => false,
			'search' => ['key_' => self::KEY_PREFIX],
			'startSearch' => true,
			'preservekeys' => true
		]);

		$items = CArrayHelper::renameObjectsKeys($items, ['name_resolved' => 'name']);
		$items = CMacrosResolverHelper::resolveItemDescriptions($items);
		$items = CMacrosResolverHelper::resolveTimeUnitMacros($items, ['delay', 'history', 'trends']);
		CArrayHelper::sort($items, ['name']);

		$history = self::getLastValues($items, 2);

		$objects = array_fill_keys(self::TYPES, []);
		$general_itemids = [];

		foreach ($items as $itemid => &$item) {
			$item['history_values'] = $history[$itemid] ?? [];
			$item['lastvalue'] = $item['history_values'] ? $item['history_values'][0]['value'] : null;
			$item['metric'] = self::metricOf($item['key_']);

			$class = self::classifyItem($item);

			if ($class === null) {
				$general_itemids[] = $itemid;
				continue;
			}

			[$type, $id] = $class;
			$tags = self::tagMap($item['tags']);

			if (!array_key_exists($id, $objects[$type])) {
				$objects[$type][$id] = [
					'type' => $type,
					'id' => (string) $id,
					'name' => self::objectName($type, $id, $tags),
					'node' => $tags['node'] ?? '',
					// The template takes the ID from the API "id" field, for example "qemu/407".
					'vmid' => in_array($type, [self::TYPE_LXC, self::TYPE_QEMU])
						? preg_replace('/^(qemu|lxc)\//', '', (string) $id)
						: '',
					'itemids' => [],
					'metrics' => [],
					'problems' => []
				];
			}

			$object = &$objects[$type][$id];
			$object['itemids'][] = $itemid;

			if ($object['node'] === '' && array_key_exists('node', $tags)) {
				$object['node'] = $tags['node'];
			}

			if ($item['metric'] !== null && !array_key_exists($item['metric'], $object['metrics'])) {
				$object['metrics'][$item['metric']] = $itemid;
			}

			unset($object);
		}
		unset($item);

		foreach ($objects as $type => &$list) {
			foreach ($list as &$object) {
				[$object['status'], $object['status_text']] = self::objectStatus($object, $items);
			}
			unset($object);

			uasort($list, static function (array $a, array $b): int {
				return strnatcasecmp($a['node'].'/'.$a['name'], $b['node'].'/'.$b['name']);
			});
		}
		unset($list);

		return [
			'host' => $hosts[0],
			'items' => $items,
			'objects' => $objects,
			'general_itemids' => $general_itemids
		];
	}

	/**
	 * Active problems of a host. Each problem gets 'object' => [type, id] or null.
	 */
	public static function getProblems(array $data, bool $show_acknowledged, bool $show_suppressed): array {
		$options = [
			'output' => ['eventid', 'objectid', 'clock', 'ns', 'name', 'severity', 'acknowledged', 'suppressed',
				'r_eventid', 'opdata'
			],
			'selectTags' => ['tag', 'value'],
			'selectSuppressionData' => ['maintenanceid', 'suppress_until', 'userid'],
			'hostids' => [$data['host']['hostid']],
			'sortfield' => ['eventid'],
			'sortorder' => ZBX_SORT_DOWN
		];

		if (!$show_acknowledged) {
			$options['acknowledged'] = false;
		}

		if (!$show_suppressed) {
			$options['suppressed'] = false;
		}

		$problems = API::Problem()->get($options);

		if (!$problems) {
			return [];
		}

		// Fallback for problems without object tags: the object of the items used by the trigger.
		$item_objects = [];

		foreach ($data['objects'] as $type => $list) {
			foreach ($list as $id => $object) {
				foreach ($object['itemids'] as $itemid) {
					$item_objects[$itemid] = [$type, (string) $id];
				}
			}
		}

		$triggers = API::Trigger()->get([
			'output' => ['triggerid'],
			'selectItems' => ['itemid'],
			'triggerids' => array_unique(array_column($problems, 'objectid')),
			'preservekeys' => true
		]);

		// Only problems of triggers on Proxmox items.
		$problems = array_filter($problems, static function (array $problem) use ($triggers, $data): bool {
			if (!array_key_exists($problem['objectid'], $triggers)) {
				return false;
			}

			foreach ($triggers[$problem['objectid']]['items'] as $trigger_item) {
				if (array_key_exists($trigger_item['itemid'], $data['items'])) {
					return true;
				}
			}

			return false;
		});

		foreach ($problems as &$problem) {
			$problem['object'] = self::classifyTags(self::tagMap($problem['tags']));

			if ($problem['object'] === null && array_key_exists($problem['objectid'], $triggers)) {
				foreach ($triggers[$problem['objectid']]['items'] as $trigger_item) {
					if (array_key_exists($trigger_item['itemid'], $item_objects)) {
						$problem['object'] = $item_objects[$trigger_item['itemid']];
						break;
					}
				}
			}

			if ($problem['object'] !== null) {
				$problem['object'][1] = (string) $problem['object'][1];
			}
		}
		unset($problem);

		return array_values($problems);
	}

	/**
	 * Count problems by severity on each object.
	 */
	public static function assignProblemCounts(array &$data, array $problems): void {
		foreach ($problems as $problem) {
			if ($problem['object'] === null) {
				continue;
			}

			[$type, $id] = $problem['object'];

			if (array_key_exists($id, $data['objects'][$type])) {
				$severity = (int) $problem['severity'];
				$data['objects'][$type][$id]['problems'][$severity] =
					($data['objects'][$type][$id]['problems'][$severity] ?? 0) + 1;
			}
		}
	}

	/**
	 * Problems neither acknowledged nor suppressed: the ones counted on tabs, selectors and the list page.
	 */
	public static function unhandledProblems(array $problems): array {
		return array_values(array_filter($problems, static function (array $problem): bool {
			return $problem['acknowledged'] != EVENT_ACKNOWLEDGED
				&& $problem['suppressed'] != ZBX_PROBLEM_SUPPRESSED_TRUE;
		}));
	}

	public static function problemsOfObject(array $problems, string $type, string $id): array {
		return array_values(array_filter($problems, static function (array $problem) use ($type, $id): bool {
			return $problem['object'] !== null && $problem['object'][0] === $type && $problem['object'][1] === $id;
		}));
	}

	/**
	 * @return array|null  [type, id] or null for items that do not belong to a discovered object.
	 */
	public static function classifyItem(array $item): ?array {
		$class = self::classifyTags(self::tagMap($item['tags'] ?? []));

		return $class ?? self::classifyKey($item['key_']);
	}

	public static function classifyTags(array $tags): ?array {
		if (array_key_exists('cluster', $tags)) {
			return [self::TYPE_CLUSTER, $tags['cluster']];
		}

		if (array_key_exists('lxc', $tags)) {
			return [self::TYPE_LXC, $tags['lxc']];
		}

		if (array_key_exists('qemu', $tags)) {
			return [self::TYPE_QEMU, $tags['qemu']];
		}

		if (array_key_exists('node', $tags)) {
			return array_key_exists('name', $tags)
				? [self::TYPE_STORAGE, $tags['node'].'/'.$tags['name']]
				: [self::TYPE_NODE, $tags['node']];
		}

		return null;
	}

	/**
	 * Fallback for items whose tags were removed or changed.
	 */
	private static function classifyKey(string $key): ?array {
		if (!preg_match('/^proxmox\.(cluster|node|lxc|qemu)\./', $key, $m)) {
			return null;
		}

		$item_key = new CItemKey();

		if ($item_key->parse($key) != CItemKey::PARSE_SUCCESS) {
			return null;
		}

		$params = [];

		for ($i = 0, $num = $item_key->getParamsNum(); $i < $num; $i++) {
			$params[] = (string) $item_key->getParam($i);
		}

		if (!$params || $params[0] === '') {
			return null;
		}

		switch ($m[1]) {
			case 'cluster':
				return [self::TYPE_CLUSTER, $params[0]];

			case 'lxc':
				return [self::TYPE_LXC, $params[0]];

			case 'qemu':
				return [self::TYPE_QEMU, $params[0]];

			default:
				return count($params) > 1
					? [self::TYPE_STORAGE, $params[0].'/'.$params[1]]
					: [self::TYPE_NODE, $params[0]];
		}
	}

	/**
	 * "proxmox.node.memused[pve1]" → "memused", "proxmox.lxc.get.data[101]" → "get.data".
	 */
	public static function metricOf(string $key): ?string {
		return preg_match('/^proxmox\.[a-z]+\.([a-z.]+)(\[|$)/', $key, $m) ? $m[1] : null;
	}

	public static function tagMap(array $tags): array {
		$map = [];

		foreach ($tags as $tag) {
			if (!array_key_exists($tag['tag'], $map)) {
				$map[$tag['tag']] = $tag['value'];
			}
		}

		return $map;
	}

	/**
	 * "pve-manager/8.2.4/faa83925c9641325" → "8.2.4".
	 */
	public static function shortVersion(string $value): string {
		return preg_match('/^pve-manager\/([^\/]+)/', $value, $m) ? $m[1] : $value;
	}

	private static function objectName(string $type, string $id, array $tags): string {
		switch ($type) {
			case self::TYPE_STORAGE:
				return $tags['name'] ?? substr($id, strpos($id, '/') + 1);

			case self::TYPE_LXC:
			case self::TYPE_QEMU:
				return $tags['name'] ?? $id;

			default:
				return $id;
		}
	}

	/**
	 * @return array  [status, text]
	 */
	private static function objectStatus(array $object, array $items): array {
		$value = static function (string $metric) use ($object, $items) {
			return array_key_exists($metric, $object['metrics'])
				? $items[$object['metrics'][$metric]]['lastvalue']
				: null;
		};

		switch ($object['type']) {
			case self::TYPE_CLUSTER:
				$quorate = $value('quorate');

				if ($quorate === null) {
					return [self::STATUS_UNKNOWN, _('Unknown')];
				}

				return (int) $quorate == 1
					? [self::STATUS_OK, _('Quorate')]
					: [self::STATUS_PROBLEM, _('Not quorate')];

			case self::TYPE_NODE:
				$online = $value('online');

				if ($online === null) {
					return [self::STATUS_UNKNOWN, _('Unknown')];
				}

				return (int) $online == 1
					? [self::STATUS_OK, _('Online')]
					: [self::STATUS_PROBLEM, _('Offline')];

			case self::TYPE_STORAGE:
				$pused = self::percent($value('disk'), $value('maxdisk'));

				if ($pused === null) {
					return [self::STATUS_UNKNOWN, _('Unknown')];
				}

				return [$pused >= 90 ? self::STATUS_WARNING : self::STATUS_OK, _s('%1$s%% used', round($pused, 1))];

			default:
				$status = $value('vmstatus');

				if ($status === null) {
					return [self::STATUS_UNKNOWN, _('Unknown')];
				}

				switch ($status) {
					case 'running':
						return [self::STATUS_OK, $status];

					case 'stopped':
						return [self::STATUS_OFF, $status];

					default:
						return [self::STATUS_WARNING, $status];
				}
		}
	}

	public static function percent($used, $total): ?float {
		if (!is_numeric($used) || !is_numeric($total) || (float) $total <= 0) {
			return null;
		}

		return (float) $used / (float) $total * 100;
	}

	private static function getLastValues(array $items, int $limit): array {
		$items = array_filter($items, static function (array $item): bool {
			return $item['value_type'] != ITEM_VALUE_TYPE_BINARY;
		});

		if (!$items) {
			return [];
		}

		return Manager::History()->getLastValues($items, $limit,
			timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::HISTORY_PERIOD))
		);
	}
}
