---
title: Route LAN command
description: Remove one withdrawn Route allowance with a placed script.
---

A parent withdraws its host catalogs, then removes the Route's LAN allowance on the recorded workload Node. The public action input is exactly `{route_id: positive integer}`. It carries no account, agent pin, secret, backend command or caller-selected file path.

The metadata behavior `@nckrtl/route-lan-commands.assets({route_id})` returns `remove_script` and `input_json`. Its manifest ships `scripts/remove_route_lan.py` through native `additionalFiles`. The metadata step and command step must run on the same placed worker. A parent keeps its own kind, run and terminal projection; this lower starts no Operation and projects nothing.

```yaml
- name: lan-assets
  target: '${{ "orbit-node-" + string(inputs.node_id) }}'
  labels: {node: '${{ string(inputs.node_id) }}'}
  task:
    type: behavior
    behaviorType: '@nckrtl/route-lan-commands'
    behaviorName: '${{ "route-lan-assets-" + string(inputs.node_id) }}'
    methodName: assets
    inputs: {route_id: '${{ inputs.intent.route_id }}'}
- name: remove-lan
  target: '${{ "orbit-node-" + string(inputs.node_id) }}'
  labels: {node: '${{ string(inputs.node_id) }}'}
  dependsOn: [{step: lan-assets, condition: {type: succeeded}}]
  task:
    type: behavior
    behaviorType: command/shell
    behaviorName: '${{ "route-lan-remove-" + string(inputs.node_id) }}'
    methodName: execute
    inputs:
      run: |
        set -eu
        sudo --preserve-env=ORBIT_ROUTE_LAN_INPUT /usr/bin/python3 "${{ steps["lan-assets"].outputs.remove_script }}"
      env:
        ORBIT_ROUTE_LAN_INPUT: '${{ steps["lan-assets"].outputs.input_json }}'
```

The script takes public input through `ORBIT_ROUTE_LAN_INPUT`. Success prints one JSON object, `{ready:true}`, and exits zero. Refusal prints only `{error_code}` and exits nonzero. It never prints UFW stderr. The caller places the action on the admitted workload Node; logical proxy Nodes 1 and 18 remain unavailable for built-in shell until the shared transport owner proves support.

The action preserves the existing UFW policy. It removes only rows with the exact comment `orbit:route-<route_id>-lan`, deletes numbered rows in descending order, refuses an owned row without a number, and verifies absence afterward. The action requires active UFW. Inactive UFW hides numbered persistent rules, so it refuses with `firewall.status_failed` and changes nothing. It never claims those rules are removed. It preserves other Routes and comments with an added suffix. The canonical host transaction lock is `/run/lock/orbit-firewall.lock`; UFW actions share it to preserve numbered-rule changes. No sleep, retry, Gateway read or agent action is used.

Certificate cleanup remains separate. The existing `AnalyticsRoutePlan::cleanupForNode` and Route mutation cleanup filter use fresh stored site catalogs to protect loaded scopes. An input captured at admission cannot establish that a scope stays unused after another publication. No immutable loaded-scope descriptor or replacement protection rule is approved yet. Parents must retain that blocker and must not treat this LAN command as authorization to remove a certificate. This lower declares no `route.analytics_*` kind and changes no Analytics parent.

Legacy callers still use the grandfathered embedded UFW engine while their parent workflows migrate. This script is the canonical command action for new workflows; its parsing and exact-comment rules are extracted from that engine. Shared firewall converge, named rules, inspection, Router LAN and certificate policy remain with their owning rewrites.
