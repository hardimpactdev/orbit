"""SPIKE(signed-orders) round 2: median, p90 and max per measure from bench2's JSON lines."""
import json
import statistics
import sys


def q(values, p):
    v = sorted(values)
    return v[min(len(v) - 1, int(round(p * (len(v) - 1))))]


def line(name, values, misses):
    if not values:
        print(f"| {name} | – | – | – | {misses} |")
        return
    print(f"| {name} | {statistics.median(values):.1f} | {q(values, .9):.1f} | {max(values):.1f} | {misses} |")


rows = [json.loads(l) for f in sys.argv[1:] for l in open(f) if l.startswith('{')]
print("| Measure (ms from broadcast start) | Median | p90 | Max | Misses (>300 ms) |\n| --- | --- | --- | --- | --- |")
for kind in sorted({r['kind'] for r in rows}):
    rs = [r for r in rows if r['kind'] == kind]
    if kind.startswith('ssh'):
        ok = [r for r in rs if r['ok']]
        bad = f"{len(rs) - len(ok)}/{len(rs)} failed"
        line(f"{kind}: target answers", [r['ready_ms'] for r in ok], bad)
        line(f"{kind}: command returns", [r['done_ms'] for r in ok], bad)
        continue
    hits = [r for r in rs if not r['miss'] and r.get('status') == 'done']
    misses = f"{len(rs) - len(hits)}/{len(rs)}"
    for key, label in [('observer_ms', 'observer receives'), ('accepted_ms', 'executor accepts'),
                       ('verified_ms', 'checks pass, nonce on disk'), ('action_ms', 'action starts (thaw)'),
                       ('thawed_ms', 'cgroup thawed'), ('ready_ms', 'target answers HTTP (action done)'),
                       ('observer_replied_ms', 'reply back at observer'), ('result_ms', 'result at Gateway'),
                       ('seen_ms', 'Gateway caller sees it')]:
        line(f"{kind}: {label}", [r[key] for r in hits if r.get(key) is not None], misses)
    fb = [r for r in rs if r['miss'] and 'fallback_ready_ms' in r]
    if fb:
        line(f"{kind}: missed, SSH fallback target answers", [r['fallback_ready_ms'] for r in fb if r['fallback_ok']], f"{sum(not r['fallback_ok'] for r in fb)}/{len(fb)} failed")
    if kind.startswith('burst'):
        rounds = {}
        for r in rs:
            rounds.setdefault(r['round'], []).append(r)
        last = [max(x['ready_ms'] for x in g if x.get('ready_ms')) for g in rounds.values()]
        line(f"{kind}: last of 5 targets answers", last, "")
        line(f"{kind}: lock wait inside executor", [r['lock_wait_ms'] for r in hits], "")
