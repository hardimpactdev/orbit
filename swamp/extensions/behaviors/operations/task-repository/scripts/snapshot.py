"""Observe a workspace tree without changing its real Git index."""

import json
import sys

from repository_common import checkout, emit, snapshot

if __name__ == "__main__":
    emit(lambda: snapshot(checkout(json.load(sys.stdin))))
