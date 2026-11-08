"""Enforce frozen base rules and limits against committed HEAD in isolated worktrees."""
import argparse
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path


def git(*args, cwd=None):
    return subprocess.run(["git", *args], cwd=cwd, capture_output=True, check=True).stdout


def evidence_repair(baseline, candidate, before, after):
    """Accept only stale labels proved by identical immutable-rule findings."""
    if before.returncode != 1 or after.returncode != 1:
        return False
    try:
        old, new = json.loads(baseline), json.loads(candidate)
        base_report = json.loads(before.stdout.splitlines()[0])
        head_report = json.loads(after.stdout.splitlines()[0])
        if ({**old, "entries": []} != {**new, "entries": []}
                or len(old["entries"]) != len(new["entries"])):
            return False
        expected = set()
        for prior, repaired in zip(old["entries"], new["entries"]):
            if {**prior, "evidence": []} != {**repaired, "evidence": []}:
                return False
            if prior["evidence"] != repaired["evidence"]:
                # The whole repaired row, including its count, must match the
                # frozen checker's findings in immutable base source exactly.
                if repaired not in base_report["violations"]:
                    return False
                expected.add(f'{prior["file"]}: {prior["rule"]} {prior["count"]} exceeds {prior["count"]}')
        return bool(expected) and (
            base_report["violations"] == head_report["violations"]
            and base_report["errors"] == head_report["errors"]
            and set(base_report["errors"]) == expected
        )
    except (ValueError, KeyError, IndexError, TypeError):
        return False


def check(base, deno):
    repo = Path(git("rev-parse", "--show-toplevel").decode().strip())
    prefix = git("rev-parse", "--show-prefix").decode().strip()
    base = git("rev-parse", "--verify", "--end-of-options", base + "^{commit}").decode().strip()
    checker = prefix + "bin/check-swamp-shape.ts"
    exists = subprocess.run(["git", "cat-file", "-e", f"{base}:{checker}"], capture_output=True)
    if exists.returncode:
        # The initial history import compares with source from before the gate.
        # The current checker still enforces its baseline derived from that source.
        print("SHAPE-BASE status=not_present_in_comparison_base")
        return 0
    baseline = git("show", f"{base}:{prefix}config/swamp-shape-baseline.json")
    head = git("rev-parse", "HEAD").decode().strip()
    with tempfile.TemporaryDirectory(prefix="swamp-shape-policy-") as scratch:
        trees = []
        try:
            for name, ref in (("rules", base), ("source", head)):
                tree = Path(scratch) / name
                git("worktree", "add", "--detach", "--no-checkout", str(tree), ref, cwd=repo)
                trees.append(tree)
                if prefix:
                    git("sparse-checkout", "set", "--cone", prefix.rstrip("/"), cwd=tree)
                git("checkout", "--quiet", "--detach", ref, cwd=tree)
            rules, source = (tree / prefix for tree in trees)
            allowance = source / "config/swamp-shape-baseline.json"
            candidate = allowance.read_bytes()
            allowance.write_bytes(baseline)
            # Derive the old-rule baseline from immutable base limits, never the
            # PR's revised policy/baseline. Intersection can only remove debt;
            # the frozen checker still rejects new sites, renames and growth.
            result = subprocess.run([deno, "run", "--allow-env", "--allow-read", "--allow-run",
                "--allow-write=" + str(allowance), str(rules / "bin/check-swamp-shape.ts"),
                "--base", base, "--write-baseline", "--python-analyzer", str(rules / "bin/swamp-shape-python.py")], cwd=source, capture_output=True, text=True, check=False)
            repaired = False
            if result.returncode and candidate != baseline:
                # Always scan HEAD with frozen rules first, including gate
                # scripts. Bootstrap only evidence-text repairs independently
                # validated against a second scan of immutable base source.
                before = subprocess.run([deno, "run", "--allow-env", "--allow-read", "--allow-run",
                    "--allow-write=" + str(rules / "config/swamp-shape-baseline.json"),
                    str(rules / "bin/check-swamp-shape.ts"), "--base", base, "--write-baseline",
                    "--python-analyzer", str(rules / "bin/swamp-shape-python.py")],
                    cwd=rules, capture_output=True, text=True, check=False)
                repaired = evidence_repair(baseline, candidate, before, result)
            status = 0 if repaired else result.returncode
            # Only the current checker emits canonical SHAPE-GATE metrics.
            print(f"SHAPE-BASE rules_commit={base} source_commit={head} status={'pass' if status == 0 else 'fail'} baseline_repair={str(repaired).lower()}")
            if not repaired:
                sys.stderr.write(result.stderr)
            return status
        finally:
            for tree in reversed(trees):
                git("worktree", "remove", "--force", str(tree), cwd=repo)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(__doc__)
    parser.add_argument("--base", required=True)
    args = parser.parse_args()
    raise SystemExit(check(args.base, os.environ.get("DENO_BIN", "deno")))
