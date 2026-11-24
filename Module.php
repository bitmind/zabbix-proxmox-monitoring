<?php declare(strict_types = 0);

namespace Modules\ProxmoxMonitoring;

use APP,
	CMenuItem,
	CRoleHelper,
	CWebUser;

use Zabbix\Core\CModule;

class Module extends CModule {

	public function init(): void {
		if (!CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA)) {
			return;
		}

		APP::Component()->get('menu.main')
			->findOrAdd(_('Monitoring'))
			->getSubmenu()
			->insertAfter(_('Latest data'),
				(new CMenuItem(_('Proxmox')))
					->setAction('proxmox.list')
					->setAliases(['proxmox.view'])
			);
	}
}
