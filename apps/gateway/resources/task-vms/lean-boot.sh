#!/usr/bin/env bash
# Keeps work off a task VM's boot. Runs as root in an Incus base image builder.
#
# The network is online once IPv4 routes: the task bridge has no IPv6, and waiting for the IPv6 link-local address
# costs a second. So the Ethernet link takes DHCPv4 only, from a networkd file that sorts before netplan's and
# otherwise matches it. The wait-online drop-in sorts after netplan's and replaces its command.
#
# The builder appends the masks of the first-boot and apt units to this script. Masks hold through the first-boot
# preset policy, which would enable a merely disabled unit again. Docker stays enabled at boot: Doctor expects it
# active on an app-dev Node, and it brings back restart-policy containers after a resume.
set -eu
printf '%s\n' '[Match]' 'Type=ether' 'Name=en*' '' '[Network]' 'DHCP=ipv4' 'LinkLocalAddressing=no' '' '[DHCP]' 'RouteMetric=100' 'UseMTU=true' \
    > /etc/systemd/network/00-orbit-task-vm.network
install -d -m 0755 /etc/systemd/system/systemd-networkd-wait-online.service.d
printf '%s\n' '[Service]' 'ExecStart=' 'ExecStart=/usr/lib/systemd/systemd-networkd-wait-online --any --ipv4 --dns --operational-state=routable' \
    > /etc/systemd/system/systemd-networkd-wait-online.service.d/zz-orbit-ipv4.conf
