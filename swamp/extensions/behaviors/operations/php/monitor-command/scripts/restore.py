"""Placed dedicated PHP monitoring action: restore."""

from monitor_common import execute, restore_pool

if __name__ == "__main__":
    raise SystemExit(execute(restore_pool))
