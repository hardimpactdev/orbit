"""One admitted SQLite target prepare action."""

import json
import sys

from sqlite_seed_common import main, target_input, target_seed


def apply(i):
    return target_seed(target_input(i["target"], "prepare", json.loads(sys.argv[2])))


if __name__ == "__main__":
    main(apply)
