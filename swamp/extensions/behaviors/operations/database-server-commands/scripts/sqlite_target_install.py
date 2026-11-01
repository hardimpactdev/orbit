"""One admitted SQLite target install action."""

import json
import sys

from sqlite_seed_common import main, target_input, target_seed


def apply(i):
    return target_seed(target_input(i["target"], "install", json.loads(sys.argv[2])))


if __name__ == "__main__":
    main(apply)
