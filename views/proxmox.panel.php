<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

use Modules\ProxmoxMonitoring\Includes\ProxmoxRenderer;

$output = [
	'object' => $data['object'],
	'panel' => ProxmoxRenderer::panel($data['proxmox'], $data['problems'], $data['tab'], $data['object'],
		$data['options']
	)->toString()
];

if ($data['tab'] !== ProxmoxRenderer::TAB_GENERAL) {
	$output['selector'] = ProxmoxRenderer::selector($data['proxmox'], $data['tab'], $data['object'], $data['hostid'])
		->toString();
}

echo json_encode($output);
