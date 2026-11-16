"""SPIKE(signed-orders) round 3: tables from bench3's JSON lines."""
import json
import statistics
import sys


def q(values, p):
    v = sorted(values)
    return v[min(len(v) - 1, int(round(p * (len(v) - 1))))]


def line(name, values, extra=""):
    values = [v for v in values if v is not None]
    if not values:
        print(f"| {name} | – | – | – | {extra} |")
        return
    print(f"| {name} | {statistics.median(values):.1f} | {q(values, .9):.1f} | {max(values):.1f} | {extra} |")


rows = [json.loads(l) for f in sys.argv[1:] for l in open(f) if l.startswith('{')]
MB = 1048576

sleeps = [r for r in rows if r['kind'] in ('sleep', 'burst_sleep') and r.get('status') == 'done']
if sleeps:
    print("Sleep (ms unless stated)\n\n| Measure | Median | p90 | Max | Note |\n| --- | --- | --- | --- | --- |")
    for key, label in [('total_ms', 'Sleep total, in the executor'), ('limits_ms', 'Record and open swap limits'), ('freeze_ms', 'Freeze the subtree'),
                       ('scan_ms', 'Scan maps and pagemap'), ('plan_ms', 'Write the plan (fsync, rename)'), ('reclaim_ms', 'Reclaim until settled')]:
        line(label, [r[key] for r in sleeps])
    line('Result at the Gateway', [r['result_ms'] for r in sleeps if r.get('result_ms')])
    line('Processes', [r['processes'] for r in sleeps])
    line('Pages recorded', [r['pages'] for r in sleeps])
    line('Plan size, KB', [r['plan_bytes'] / 1024 for r in sleeps])
    line('memory.current before, MB', [r['memory_before'] / MB for r in sleeps])
    line('memory.current asleep, MB', [r['memory_after'] / MB for r in sleeps])
    line('swap.current asleep, MB', [r['swap_after'] / MB for r in sleeps])
    line('anon asleep, MB', [r['anon_after'] / MB for r in sleeps])
    line('file asleep, MB', [r['file_after'] / MB for r in sleeps])
    print(f"\n{len(sleeps)} sleeps.\n")

for kind in sorted({r['kind'] for r in rows if 'wake' in r['kind']}):
    for threads in sorted({r.get('threads') or 0 for r in rows if r['kind'] == kind}):
        ws = [r for r in rows if r['kind'] == kind and (r.get('threads') or 0) == threads]
        hits = [r for r in ws if r['status'] == 'done' and (not r['miss'] or kind == 'burst_wake')]
        misses = f"{len(ws) - len(hits)}/{len(ws)} over 300 ms"
        print(f"{kind}, {threads} restore threads ({len(ws)} wakes, {misses}), ms from the Gateway's broadcast\n")
        print("| Measure | Median | p90 | Max | |\n| --- | --- | --- | --- | --- |")
        for key, label in [('observer_ms', 'Observer receives the order'), ('verified_ms', 'Executor: caller and order checks pass'),
                           ('restore_end_ms', 'Restore done (still frozen)'), ('thawed_ms', 'Thawed'), ('limits_closed_ms', 'Swap limits closed'),
                           ('ready_ms', 'Target answers HTTP'), ('listener_ms', 'Result at the Gateway listener'), ('seen_ms', 'Result in the waiting request (Valkey pop)')]:
            line(label, [r[key] for r in hits])
        line('Restore alone', [r['restore_ms'] for r in hits])
        line('Restore pages', [r['pages'] for r in hits])
        line('Restore MB/s', [r['mb_per_s'] for r in hits])
        line('Hand-off listener → request', [r['handoff_ms'] for r in hits])
        if kind == 'burst_wake':
            rounds = {}
            for r in ws:
                rounds.setdefault(r['round'], []).append(r)
            line('Last of 5 targets answers', [max(x['ready_ms'] for x in g if x['ready_ms']) for g in rounds.values()])
            line('Last of 5 results in its request', [max(x['seen_ms'] for x in g if x['seen_ms']) for g in rounds.values()])
        fb = [r for r in ws if r['miss']]
        for r in (fb if kind != 'burst_wake' else []):
            print(f"| Miss: ready at {r['ready_ms'] and round(r['ready_ms'], 1)} ms; fallback {r['fallback']} ({r['fallback_reason']}) after {r['fallback_ms'] and round(r['fallback_ms'], 1)} ms | | | | |")
        print()
