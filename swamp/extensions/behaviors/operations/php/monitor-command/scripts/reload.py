"""Placed dedicated PHP monitoring action: reload."""

from monitor_common import execute, reload_pool

if __name__ == "__main__":
    raise SystemExit(execute(reload_pool))
