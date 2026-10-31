"""Enforce frozen base rules and limits against committed HEAD in isolated worktrees."""
import argparse
import os
import subprocess
import sys
import tempfile
from pathlib import Path


def git(*args, cwd=None):
    return subprocess.run(["git", *args], cwd=cwd, capture_output=True, check=True).stdout


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
            allowance.write_bytes(baseline)
            # Derive the old-rule baseline from immutable base limits, never the
            # PR's revised policy/baseline. Intersection can only remove debt;
            # the frozen checker still rejects new sites, renames and growth.
            result = subprocess.run([deno, "run", "--allow-env", "--allow-read", "--allow-run",
                "--allow-write=" + str(allowance), str(rules / "bin/check-swamp-shape.ts"),
                "--base", base, "--write-baseline", "--python-analyzer", str(rules / "bin/swamp-shape-python.py")], cwd=source, capture_output=True, text=True, check=False)
            # Only the current checker emits canonical SHAPE-GATE metrics.
            print(f"SHAPE-BASE rules_commit={base} source_commit={head} status={'pass' if result.returncode == 0 else 'fail'}")
            sys.stderr.write(result.stderr)
            return result.returncode
        finally:
            for tree in reversed(trees):
                git("worktree", "remove", "--force", str(tree), cwd=repo)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(__doc__)
    parser.add_argument("--base", required=True)
    args = parser.parse_args()
    raise SystemExit(check(args.base, os.environ.get("DENO_BIN", "deno")))
