<?php declare(strict_types = 0);

namespace Modules\ProxmoxMonitoring\Actions;

use CControllerResponseData;

/**
 * Selector and panel of the detail page, as JSON, for object selection and refresh without reloading the page.
 */
class ProxmoxPanel extends ProxmoxDetailAction {

	protected function doAction(): void {
		$this->setResponse(new CControllerResponseData($this->prepareData()));
	}
}
