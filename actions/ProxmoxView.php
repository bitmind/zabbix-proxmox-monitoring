<?php declare(strict_types = 0);

namespace Modules\ProxmoxMonitoring\Actions;

use CControllerResponseData,
	CWebUser;

/**
 * Detail page of a Proxmox VE host, with a tab for each object type.
 */
class ProxmoxView extends ProxmoxDetailAction {

	protected function doAction(): void {
		$data = $this->prepareData();
		$data['refresh_interval'] = CWebUser::getRefresh() * 1000;

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Proxmox').': '.$data['proxmox']['host']['name']);

		$this->setResponse($response);
	}
}
