<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

use Modules\ProxmoxMonitoring\Includes\ProxmoxData;
use Modules\ProxmoxMonitoring\Includes\ProxmoxHtml;
use Modules\ProxmoxMonitoring\Includes\ProxmoxRenderer;

$this->addJsFile('items.js');
$this->addJsFile('multilineinput.js');
$this->includeJsFile('proxmox.view.js.php');

$proxmox = $data['proxmox'];
$host = $proxmox['host'];
$hostid = $data['hostid'];

// Tabs.
$tabs = new CTag('ul', true);
$tab_labels = [ProxmoxRenderer::TAB_GENERAL => _('General')] + ProxmoxData::typeLabels();

foreach ($tab_labels as $tab => $label) {
	$content = [$label];

	if ($tab !== ProxmoxRenderer::TAB_GENERAL) {
		$content[] = (new CSpan(count($proxmox['objects'][$tab])))->addClass('pve-tab-count');

		$severities = [];

		foreach ($proxmox['objects'][$tab] as $object) {
			foreach ($object['problems'] as $severity => $count) {
				$severities[$severity] = ($severities[$severity] ?? 0) + $count;
			}
		}

		$content[] = ProxmoxHtml::severityDot($severities);
	}

	$tabs->addItem(
		(new CTag('li', true,
			new CLink($content,
				(new CUrl('zabbix.php'))
					->setArgument('action', 'proxmox.view')
					->setArgument('hostid', $hostid)
					->setArgument('tab', $tab)
			)
		))->addClass($tab === $data['tab'] ? 'pve-tab-active' : null)
	);
}

// Body: selector on the left (not on General) and panel on the right.
$panel = ProxmoxRenderer::panel($proxmox, $data['problems'], $data['tab'], $data['object'], $data['options']);

if ($data['tab'] === ProxmoxRenderer::TAB_GENERAL) {
	$body = (new CDiv((new CDiv($panel))->addClass('pve-panel-container')))->addClass('pve-body');
}
else {
	$body = (new CDiv([
		(new CDiv([
			(new CDiv(
				(new CTextBox('pve_search'))
					->setAttribute('placeholder', _('Search'))
					->setAttribute('autocomplete', 'off')
					->addClass('pve-search')
			))->addClass('pve-nav-search'),
			(new CDiv(ProxmoxRenderer::selector($proxmox, $data['tab'], $data['object'], $hostid)))
				->addClass('pve-nav-container')
		]))->addClass('pve-nav'),
		(new CDiv($panel))->addClass('pve-panel-container')
	]))
		->addClass('pve-body')
		->addClass($data['tab'] === ProxmoxData::TYPE_STORAGE ? 'pve-body-stacked' : 'pve-body-with-nav');
}

$navigation = (new CList())->addItem(
	new CBreadcrumbs([
		(new CSpan())->addItem(
			new CLink(_('Proxmox'), (new CUrl('zabbix.php'))->setArgument('action', 'proxmox.list'))
		),
		(new CSpan())->addItem($host['name'])
	])
);

$host_info = [];

if ($host['status'] != HOST_STATUS_MONITORED) {
	$host_info[] = (new CSpan(_('Disabled')))->addClass(ZBX_STYLE_RED);
}
elseif ($host['maintenance_status'] == HOST_MAINTENANCE_STATUS_ON) {
	$host_info[] = makeMaintenanceIcon($host['maintenance_type'], _('Maintenance'), '');
}

(new CHtmlPage())
	->setTitle(_('Proxmox').': '.$host['name'])
	->setNavigation($navigation)
	->setControls(
		(new CTag('nav', true,
			(new CList())
				->addItem($host_info)
				->addItem(
					new CLink(_('Latest data'),
						(new CUrl('zabbix.php'))
							->setArgument('action', 'latest.view')
							->setArgument('hostids', [$hostid])
							->setArgument('filter_set', 1)
					)
				)
				->addItem(
					new CLink(_('Problems'),
						(new CUrl('zabbix.php'))
							->setArgument('action', 'problem.view')
							->setArgument('hostids', [$hostid])
							->setArgument('filter_set', 1)
					)
				)
		))->setAttribute('aria-label', _('Content controls'))
	)
	->addItem(
		(new CDiv([
			(new CTag('nav', true, $tabs))->addClass('pve-tabs'),
			$body
		]))
			->setId('pve-view')
			->addClass('pve-view')
	)
	->show();

(new CScriptTag('view.init('.json_encode([
	'hostid' => $hostid,
	'tab' => $data['tab'],
	'object' => $data['object'],
	'refresh_interval' => $data['refresh_interval']
]).');'))
	->setOnDocumentReady()
	->show();
