# Exact-tip retained Incus DNS Doctor ownership proof

- Date: 2026-07-19
- Topology: `dev-7df17b` (`operator_gateway_app-dev`)
- Provider/host: Incus on `beast`
- Instances: `orbit-e2e-dev-7df17b-operator`, `orbit-e2e-dev-7df17b-gateway`, `orbit-e2e-dev-7df17b-dev`
- Candidate HEAD: `570226b4206820219254bb5ff669dae0fae9c056`
- Candidate launcher SHA-256, local and retained operator checkout: `eb19bf3561cf7627029de7e9b105b1460b72dacbf0511adb9004727c4fd4068a`
- E2E suites were not run. This was retained-topology feature proof only.

## Runtime substrate

The retained gateway reported both relevant Swarm services ready. The VPN task
remained stable throughout the perturbation/restore sequence:

```text
orbit_orbit-dns 1/1
orbit_orbit-vpn 1/1
orbit_orbit-vpn.1 | Running 12 minutes ago
```

The live DNS service mounted the base file and the owner projection directory
read-only:

```json
[
  {"Type":"bind","Source":"/home/orbit/.config/orbit/dnsmasq.conf","Target":"/etc/dnsmasq.conf","ReadOnly":true},
  {"Type":"bind","Source":"/home/orbit/.config/orbit/dnsmasq.d","Target":"/etc/dnsmasq.d","ReadOnly":true}
]
```

The base config contained resolver policy and only the two exact `conf-file`
includes. It contained zero `address=/` records. The live VPN healthcheck kept
`$1`, `$dns_ip`, and `${dns_ip}` intact, proving Compose did not consume the
forwarding variables.

The final canonical artifacts were:

```text
0ddabea308826d41e501f5f2be697fda0af02a8f80b253775e6ddcd7379044cc  dnsmasq.conf
7435fcdf891e7f4c0a76ec36348b6de89501c4269b7da4257e6211d526fd2258  dnsmasq.d/10-node-records.conf
3a276f47dbd1a06d1cabcbd92717dc4a2358bbce5aa096d292272a93eba094d3  dnsmasq.d/20-proxy-records.conf
```

## Owner isolation

All checks and repairs ran from the retained operator's exact source-mounted
launcher and targeted gateway plus one exact family/key.

### Node owns semantic node records

The prepared snapshot began with five obsolete records for `agent`, `edge-1`,
and `prod`. Exact-key verification reported one issue, exclusively:

```text
family=node
code=node.dns_mapping_mismatch
issues=1
actual=[five obsolete semantic records]
```

At the same state, exact proxy and tool checks returned `healthy=true` and zero
issues. Node-scoped restore completed with `fixed=1`, removed the obsolete
records, and returned the node artifact to its canonical hash.

### Proxy owns private service records

The proxy artifact was deliberately changed by appending
`address=/proof.invalid/203.0.113.99`. Exact-key verification then returned one
`proxy.dns_mapping_mismatch`. Concurrent exact node and tool checks returned
zero issues. Proxy-scoped restore completed with `fixed=1` and returned only the
proxy artifact to its canonical hash.

### Tool owns base resolver configuration

The base file was deliberately changed by appending `server=9.9.9.9`.
Exact-key verification returned one `tool.dns_base_config_mismatch` with
`components=["base_config"]`, the expected projection source/destination, and
`projection_read_only=true`. Concurrent exact node and proxy checks returned
zero issues. Tool-scoped restore completed with `fixed=1` and returned only the
base artifact to its canonical hash.

## Final health and peer resolution

Final exact-key verification independently returned `exit_code=0`,
`healthy=true`, and zero issues for:

```text
node.dns_mapping_mismatch
proxy.dns_mapping_mismatch
tool.dns_base_config_mismatch
```

Both retained peers (`operator` and `dev`) resolved the canonical names through
the VPN DNS endpoint:

```text
orbit.gateway @10.6.0.1 -> 10.6.0.2
orbit.operator @10.6.0.1 -> 10.6.0.3
orbit.test @10.6.0.1 -> 10.6.0.4
test @10.6.0.1 -> 10.6.0.4
```

The source-mounted lease helper containers exposed a separate false-negative
image healthcheck: the helper servers bind the WireGuard address
while `orbit-gateway-healthcheck` probes `127.0.0.1:8080`. This did not affect
the proof path: every gateway-backed Doctor request completed, both DNS/VPN
services stayed `1/1`, and peer DNS resolution passed from both peers.

## Reviewed tip relation

The final independently reviewed candidate is
`57732ed3aedb73d78b537e2c0ff90eddfa5d7745`. The feature's DNS delta from the
retained executable proof tip is explicit ownership wording in
`domains/16_dns/README.md` and
`domains/16_dns/2_dns-list/technical/1_dns-list.md`. No executable, test,
configuration, or runtime artifact changed, so the retained proof exercises the
identical DNS runtime bytes reviewed at the final candidate tip. The candidate
also integrates main commit `589411b172c47dd246957f3152e27a36f001e9ce`, whose
delta changes only unrelated fleet-update implementation, tests, and
documentation; the independent reviewer confirmed it does not touch any DNS,
Doctor, Node, Proxy, or VPN ownership surface. The later current-main delta
through `01064868ce7123ecc8befdee8709acd6df22445e` changes only tracked
`.orbit/sessions/` release archives and their index, so it also leaves the
proved runtime bytes unchanged.
