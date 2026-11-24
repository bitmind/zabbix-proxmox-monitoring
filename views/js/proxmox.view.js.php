<?php declare(strict_types = 0);

/**
 * @var CView $this
 */
?>

<script>
	const view = new class {

		init({hostid, tab, object, refresh_interval}) {
			this.hostid = hostid;
			this.tab = tab;
			this.object = object;
			this.refresh_interval = refresh_interval;
			this.request = null;
			this.timer = null;

			this.container = document.getElementById('pve-view');
			this.panel_container = this.container.querySelector('.pve-panel-container');
			this.nav_container = this.container.querySelector('.pve-nav-container');
			this.search = this.container.querySelector('.pve-search');

			this.container.addEventListener('click', (e) => this._onClick(e));
			this.container.addEventListener('change', (e) => this._onChange(e));

			if (this.search !== null) {
				this.search.addEventListener('input', () => {
					this._applySearch();
					this._fitTable();
				});
			}

			$.subscribe('acknowledge.create', () => this.refresh());

			this._fitTable();
			this._scrollToSelected();
			this._updateUrl();
			this._startTimer();
		}

		select(object) {
			if (object === this.object) {
				return;
			}

			this.object = object;

			for (const item of this.container.querySelectorAll('.pve-nav-item')) {
				const link = item.querySelector('.pve-nav-link');

				item.classList.toggle('pve-nav-selected', link !== null && link.dataset.object === object);
			}

			this._updateUrl();
			this.refresh({}, true);
		}

		refresh(params = {}, loading = false) {
			if (this.request !== null) {
				this.request.abort();
			}

			const curl = new Curl('zabbix.php');

			curl.setArgument('action', 'proxmox.panel');
			curl.setArgument('hostid', this.hostid);
			curl.setArgument('tab', this.tab);

			if (this.object !== null) {
				curl.setArgument('object', this.object);
			}

			for (const [name, value] of Object.entries(params)) {
				curl.setArgument(name, value);
			}

			if (loading) {
				this.panel_container.classList.add('is-loading');
			}

			this.request = new AbortController();

			fetch(curl.getUrl(), {signal: this.request.signal})
				.then((response) => response.json())
				.then((response) => {
					if ('error' in response) {
						throw {error: response.error};
					}

					this._clearMessages();
					this.panel_container.innerHTML = response.panel;

					if ('selector' in response && this.nav_container !== null) {
						const scroll = this.nav_container.scrollTop;

						this.nav_container.innerHTML = response.selector;
						this._applySearch();
						this._fitTable();
						this.nav_container.scrollTop = scroll;
					}

				})
				.catch((exception) => {
					if (exception.name === 'AbortError') {
						return;
					}

					this._clearMessages();

					let title, messages;

					if (typeof exception === 'object' && 'error' in exception) {
						title = exception.error.title;
						messages = exception.error.messages;
					}
					else {
						messages = [<?= json_encode(_('Unexpected server error.')) ?>];
					}

					const message_box = makeMessageBox('bad', messages, title)[0];

					message_box.classList.add('pve-message');
					this.panel_container.parentNode.insertBefore(message_box, this.panel_container);
				})
				.finally(() => {
					this.panel_container.classList.remove('is-loading');
					this.request = null;
				});
		}

		_onClick(e) {
			let link = e.target.closest('.pve-nav-link');

			if (link === null && e.target.closest('a, button, input, select') === null) {
				const row = e.target.closest('tr.pve-nav-item');

				link = row !== null ? row.querySelector('.pve-nav-link') : null;
			}

			if (link !== null && e.button === 0 && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
				e.preventDefault();
				this.select(link.dataset.object);

				return;
			}

			const counter = e.target.closest('.pve-counter[data-tab]');

			if (counter !== null) {
				const curl = new Curl('zabbix.php');

				curl.setArgument('action', 'proxmox.view');
				curl.setArgument('hostid', this.hostid);
				curl.setArgument('tab', counter.dataset.tab);
				location.href = curl.getUrl();
			}
		}

		_onChange(e) {
			if (e.target.matches('.pve-show-ack')) {
				this.refresh({show_ack: e.target.checked ? 1 : 0}, true);
			}
			else if (e.target.matches('.pve-show-suppressed')) {
				this.refresh({show_suppressed: e.target.checked ? 1 : 0}, true);
			}
		}

		_applySearch() {
			if (this.search === null || this.nav_container === null) {
				return;
			}

			const needle = this.search.value.trim().toLowerCase();
			let group = null;
			let group_visible = false;

			const closeGroup = () => {
				if (group !== null) {
					group.style.display = group_visible ? '' : 'none';
				}
			};

			for (const li of this.nav_container.querySelectorAll('.pve-nav-group, .pve-nav-item')) {
				if (li.classList.contains('pve-nav-group')) {
					closeGroup();
					group = li;
					group_visible = false;
					continue;
				}

				const link = li.querySelector('.pve-nav-link');

				if (link === null) {
					continue;
				}

				const visible = needle === '' || link.dataset.search.includes(needle);

				li.style.display = visible ? '' : 'none';
				group_visible = group_visible || visible;
			}

			closeGroup();
		}

		/**
		 * Storage table: show about 10 rows, scroll the rest.
		 */
		_fitTable() {
			const table = this.nav_container !== null
				? this.nav_container.querySelector('.pve-storage-table')
				: null;

			if (table === null) {
				return;
			}

			const rows = [...table.querySelectorAll('tbody tr.pve-nav-item')]
				.filter((row) => row.style.display !== 'none');

			if (rows.length <= 10) {
				this.nav_container.style.maxHeight = '';

				return;
			}

			const last = rows[9];

			this.nav_container.style.maxHeight = (last.offsetTop + last.offsetHeight) + 'px';
		}

		_scrollToSelected() {
			const selected = this.container.querySelector('.pve-nav-selected');

			if (selected !== null && this.nav_container !== null) {
				const top = selected.offsetTop - this.nav_container.offsetTop;

				if (top > this.nav_container.clientHeight) {
					this.nav_container.scrollTop = top - this.nav_container.clientHeight / 2;
				}
			}
		}

		_updateUrl() {
			const curl = new Curl('zabbix.php');

			curl.setArgument('action', 'proxmox.view');
			curl.setArgument('hostid', this.hostid);
			curl.setArgument('tab', this.tab);

			if (this.object !== null) {
				curl.setArgument('object', this.object);
			}

			history.replaceState(null, '', curl.getUrl());
		}

		_clearMessages() {
			for (const message of this.container.querySelectorAll('.pve-message')) {
				message.remove();
			}
		}

		_startTimer() {
			if (this.refresh_interval <= 0) {
				return;
			}

			this.timer = setInterval(() => {
				if (document.hidden || overlays_stack.length > 0 || this.request !== null) {
					return;
				}

				this.refresh();
			}, this.refresh_interval);
		}
	};
</script>
