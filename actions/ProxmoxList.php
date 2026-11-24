<?php declare(strict_types = 0);

namespace Modules\ProxmoxMonitoring\Actions;

use API,
	CController,
	CControllerResponseData,
	CRoleHelper;

use Modules\ProxmoxMonitoring\Includes\ProxmoxData;

/**
 * List of the Proxmox VE hosts with a summary of each one.
 */
class ProxmoxList extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'filter_name' => 'string',
			'filter_groupids' => 'array_id',
			'filter_set' => 'in 1',
			'filter_rst' => 'in 1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new \CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA);
	}

	protected function doAction(): void {
		$filter = [
			'name' => $this->hasInput('filter_rst') ? '' : $this->getInput('filter_name', ''),
			'groupids' => $this->hasInput('filter_rst') ? [] : $this->getInput('filter_groupids', [])
		];

		$groups = $filter['groupids']
			? API::HostGroup()->get([
				'output' => ['groupid', 'name'],
				'groupids' => $filter['groupids']
			])
			: [];

		$hosts = ProxmoxData::getProxmoxHosts([
			'name' => $filter['name'],
			'groupids' => $filter['groupids'] ?: null
		]);

		$response = new CControllerResponseData([
			'filter' => $filter,
			'filter_groups' => array_map(static function (array $group): array {
				return ['id' => $group['groupid'], 'name' => $group['name']];
			}, $groups),
			'hosts' => $hosts,
			'summaries' => ProxmoxData::getSummaries($hosts)
		]);
		$response->setTitle(_('Proxmox'));

		$this->setResponse($response);
	}
}
