"""Placed dedicated PHP monitoring action: publish."""

from monitor_common import execute, publish_pool

if __name__ == "__main__":
    raise SystemExit(execute(publish_pool))
