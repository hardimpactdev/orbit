"""SPIKE(signed-orders): median, p90 and max per measure from the bench's JSON lines."""
import json, sys, statistics

rows = [json.loads(l) for l in open(sys.argv[1]) if l.startswith('{')]

def q(values, p):
    v = sorted(values)
    return v[min(len(v) - 1, int(round(p * (len(v) - 1))))]

def line(name, values, misses, total):
    if not values:
        print(f"| {name} | – | – | – | {misses}/{total} |"); return
    print(f"| {name} | {statistics.median(values):.1f} | {q(values, .9):.1f} | {max(values):.1f} | {misses}/{total} |")

orders = [r for r in rows if r['kind'] == 'order']
hits = [r for r in orders if not r['miss']]
misses = len(orders) - len(hits)
print("| Measure (ms from broadcast start) | Median | p90 | Max | Misses |\n| --- | --- | --- | --- | --- |")
for key, name in [('broadcast_call_ms', 'Order: Reverb HTTP publish returns'), ('observer_ms', 'Order: observer receives it'), ('exec_ms', 'Order: executor process starts'),
                  ('verified_ms', 'Order: signature and checks pass'), ('unit_ms', 'Order: unit starts'), ('observer_replied_ms', 'Order: executor reply back at observer'),
                  ('result_route_ms', 'Order: result reaches the Gateway route'), ('result_seen_ms', 'Order: Gateway caller sees the result')]:
    if all(key in r for r in hits):
        line(name, [r[key] for r in hits], misses, len(orders))
fb = [r for r in orders if r['miss']]
if fb:
    line('Order missed: SSH fallback done', [r['fallback_done_ms'] for r in fb], sum(not r['fallback_ok'] for r in fb), len(fb))
for kind, name in [('ssh_shared', 'SSH, shared connection'), ('ssh_fresh', 'SSH, fresh connection')]:
    rs = [r for r in rows if r['kind'] == kind]
    bad = sum(not r['ok'] for r in rs)
    line(f"{name}: unit starts", [r['unit_ms'] for r in rs if r['ok']], bad, len(rs))
    line(f"{name}: command returns", [r['done_ms'] for r in rs if r['ok']], bad, len(rs))
