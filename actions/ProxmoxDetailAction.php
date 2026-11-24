<?php declare(strict_types = 0);

namespace Modules\ProxmoxMonitoring\Actions;

use CController,
	CControllerResponseFatal,
	CProfile,
	CRoleHelper,
	CUrl;

use Modules\ProxmoxMonitoring\Includes\ProxmoxData;
use Modules\ProxmoxMonitoring\Includes\ProxmoxRenderer;

/**
 * Common input and data for the detail page and its panel refresh.
 */
abstract class ProxmoxDetailAction extends CController {

	protected const PROFILE_SHOW_ACK = 'web.proxmox.show_ack';
	protected const PROFILE_SHOW_SUPPRESSED = 'web.proxmox.show_suppressed';

	protected ?array $proxmox = null;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' => 'required|db hosts.hostid',
			'tab' => 'in '.implode(',', array_merge([ProxmoxRenderer::TAB_GENERAL], ProxmoxData::TYPES)),
			'object' => 'string',
			'show_ack' => 'in 0,1',
			'show_suppressed' => 'in 0,1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		if (!$this->checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA)) {
			return false;
		}

		$this->proxmox = ProxmoxData::loadHost($this->getInput('hostid'));

		return $this->proxmox !== null;
	}

	/**
	 * Data shared by the page and the panel.
	 */
	protected function prepareData(): array {
		foreach ([
			'show_ack' => self::PROFILE_SHOW_ACK,
			'show_suppressed' => self::PROFILE_SHOW_SUPPRESSED
		] as $field => $idx) {
			if ($this->hasInput($field)) {
				CProfile::update($idx, $this->getInput($field), PROFILE_TYPE_STR);
			}
		}

		$options = [
			'show_ack' => (bool) CProfile::get(self::PROFILE_SHOW_ACK, '0'),
			'show_suppressed' => (bool) CProfile::get(self::PROFILE_SHOW_SUPPRESSED, '0')
		];

		$data = $this->proxmox;

		// All active problems; the renderer filters them by the acknowledged/suppressed options.
		$problems = ProxmoxData::getProblems($data, true, true);

		// Counts on tabs and selectors: only problems neither acknowledged nor suppressed.
		ProxmoxData::assignProblemCounts($data, ProxmoxData::unhandledProblems($problems));

		$tab = $this->getInput('tab', ProxmoxRenderer::TAB_GENERAL);
		$object = null;

		if ($tab !== ProxmoxRenderer::TAB_GENERAL) {
			$object = $this->getInput('object', '');

			if (!array_key_exists($object, $data['objects'][$tab])) {
				$object = $data['objects'][$tab] ? (string) array_key_first($data['objects'][$tab]) : null;
			}
		}

		$hostid = $data['host']['hostid'];
		$options['backurl'] = (new CUrl('zabbix.php'))
			->setArgument('action', 'proxmox.view')
			->setArgument('hostid', $hostid)
			->setArgument('tab', $tab)
			->setArgument('object', $object)
			->getUrl();

		return [
			'proxmox' => $data,
			'problems' => $problems,
			'hostid' => $hostid,
			'tab' => $tab,
			'object' => $object,
			'options' => $options
		];
	}
}
