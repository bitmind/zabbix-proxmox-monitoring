# Proxmox monitoring for Zabbix

Frontend module for Zabbix 7.0 that shows Proxmox VE hosts monitored with the official template **Proxmox VE by HTTP**, organized by cluster, node, storage, LXC container and QEMU virtual machine.

The module reads only what Zabbix already collects, through the Zabbix API and with the permissions of the logged-in user.

## Pages

The module is in **Monitoring → Proxmox**.

**Proxmox list** shows every host that has the template, with API status, PVE version, cluster and quorum, nodes online, storages, LXC and QEMU running, and active problems by severity. It can be filtered by host group and name.

**Proxmox detail** has a tab for each area. Each tab shows the number of objects and a dot in the color of the highest severity among their problems.

- **General**: API status, PVE versions, cluster, number of discovered objects by type, the Proxmox problems of the host and the items that are not discovered.
- **Clusters, Nodes, LXC, QEMU**: an object list on the left, with status and problem count, and a search box. The selected object shows:
  1. Overview: main values (status, version, kernel, uptime, load, network, disk I/O) and gauges (CPU, memory, root filesystem, swap, disk space)
  2. Problems of the object, with **Show acknowledged** and **Show suppressed** and the number of each
  3. Latest data of all the items of the object, with the item menu and the Graph/History link
- **Storages**: a table with problems, usage, size, type and content of each storage; the selected storage shows its problems and latest data.

Problem counts on tabs, lists and tables exclude acknowledged and suppressed problems. The detail refreshes with the user's refresh interval.

## How objects are found

Hosts are found by the item `proxmox.api.available`, so clones of the template work too. Only items with keys `proxmox.*` are used, so the host can also have other templates.

Items are grouped into objects by the tags set by the template:

| Tags | Object |
|---|---|
| `cluster` | Cluster |
| `lxc` | LXC container |
| `qemu` | QEMU virtual machine |
| `node` and `name` | Storage |
| `node` only | Node |

Item key parameters are used when the tags are missing. Problems inherit item tags in Zabbix 7.0, so they are assigned to objects with the same rules; problems without these tags are assigned through the items of their trigger.

## Requirements

- Zabbix 7.0
- Hosts linked to the template **Proxmox VE by HTTP** (or a clone of it)
- User role with access to **Monitoring → Latest data**

## Installation

1. Copy this repository to the frontend modules directory, for example `/usr/share/zabbix/modules/proxmox-monitoring`.
2. Go to **Administration → General → Modules** and click **Scan directory**.
3. Enable **Proxmox monitoring**.

## License

GNU Affero General Public License v3.0, see [LICENSE](LICENSE).
