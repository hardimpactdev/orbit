"""Placed dedicated PHP monitoring action: inspect."""

from monitor_common import execute, inspect_pool

if __name__ == "__main__":
    raise SystemExit(execute(inspect_pool))
