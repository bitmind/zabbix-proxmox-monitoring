<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

use Modules\ProxmoxMonitoring\Includes\ProxmoxData;
use Modules\ProxmoxMonitoring\Includes\ProxmoxHtml;

$filter = (new CForm('get'))
	->cleanItems()
	->setName('pve_filter')
	->addVar('action', 'proxmox.list')
	->addItem(
		(new CDiv([
			new CLabel(_('Host groups'), 'filter_groupids__ms'),
			(new CMultiSelect([
				'name' => 'filter_groupids[]',
				'object_name' => 'hostGroup',
				'data' => $data['filter_groups'],
				'popup' => [
					'parameters' => [
						'srctbl' => 'host_groups',
						'srcfld1' => 'groupid',
						'dstfrm' => 'pve_filter',
						'dstfld1' => 'filter_groupids_',
						'with_hosts' => true
					]
				]
			]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH),
			new CLabel(_('Name'), 'filter_name'),
			(new CTextBox('filter_name', $data['filter']['name']))
				->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH)
				->setAttribute('placeholder', _('type here to search')),
			new CSubmitButton(_('Apply'), 'filter_set', 1),
			(new CRedirectButton(_('Reset'),
				(new CUrl('zabbix.php'))
					->setArgument('action', 'proxmox.list')
					->setArgument('filter_rst', 1)
			))->addClass(ZBX_STYLE_BTN_ALT)
		]))->addClass('pve-filterbar')
	);

$table = (new CTableInfo())
	->setHeader([
		_('Name'),
		_('API'),
		_('Version'),
		_('Cluster'),
		_('Nodes'),
		_('Storages'),
		_('LXC'),
		_('QEMU'),
		_('Problems')
	])
	->addClass('pve-list');

$ratio = static function (int $part, int $total) {
	if ($total == 0) {
		return (new CSpan('0'))->addClass(ZBX_STYLE_GREY);
	}

	return [
		(new CSpan($part))->addClass($part == $total ? ZBX_STYLE_GREEN : null),
		(new CSpan(' / '.$total))->addClass(ZBX_STYLE_GREY)
	];
};

foreach ($data['hosts'] as $hostid => $host) {
	$s = $data['summaries'][$hostid];

	$name = [
		new CLink($host['name'],
			(new CUrl('zabbix.php'))
				->setArgument('action', 'proxmox.view')
				->setArgument('hostid', $hostid)
		)
	];

	if ($host['status'] != HOST_STATUS_MONITORED) {
		$name[] = ' ';
		$name[] = (new CSpan(_('Disabled')))->addClass(ZBX_STYLE_RED);
	}
	elseif ($host['maintenance_status'] == HOST_MAINTENANCE_STATUS_ON) {
		$name[] = makeMaintenanceIcon($host['maintenance_type'], _('Maintenance'), '');
	}

	if ($s['api_status'] === null) {
		$api = [ProxmoxHtml::statusDot(ProxmoxData::STATUS_UNKNOWN), _('Unknown')];
	}
	elseif ((int) $s['api_status'] == 200) {
		$api = [ProxmoxHtml::statusDot(ProxmoxData::STATUS_OK), _('Available')];
	}
	else {
		$api = [ProxmoxHtml::statusDot(ProxmoxData::STATUS_PROBLEM), _s('Unavailable (%1$s)', $s['api_status'])];
	}

	$clusters = [];

	foreach ($s['clusters'] as $cluster) {
		$ok = $cluster['quorate'] !== null && (int) $cluster['quorate'] == 1;
		$clusters[] = (new CSpan([
			ProxmoxHtml::statusDot($ok ? ProxmoxData::STATUS_OK : ProxmoxData::STATUS_PROBLEM,
				$ok ? _('Quorate') : _('Not quorate')
			),
			$cluster['name']
		]))->addClass('pve-inline');
	}

	$table->addRow([
		(new CCol($name))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($api))->addClass(ZBX_STYLE_NOWRAP),
		$s['versions'] ? implode(', ', $s['versions']) : (new CSpan('—'))->addClass(ZBX_STYLE_GREY),
		$clusters ?: (new CSpan(_('Standalone')))->addClass(ZBX_STYLE_GREY),
		$ratio($s['nodes_online'], $s['nodes']),
		$s['storages'],
		$ratio($s['lxc_running'], $s['lxc']),
		$ratio($s['qemu_running'], $s['qemu']),
		ProxmoxHtml::problemBadges($s['problems'],
			(new CUrl('zabbix.php'))
				->setArgument('action', 'problem.view')
				->setArgument('hostids', [$hostid])
				->setArgument('filter_set', 1)
		) ?: (new CSpan(_('None')))->addClass(ZBX_STYLE_GREEN)
	]);
}

(new CHtmlPage())
	->setTitle(_('Proxmox'))
	->addItem($filter)
	->addItem($table)
	->show();
