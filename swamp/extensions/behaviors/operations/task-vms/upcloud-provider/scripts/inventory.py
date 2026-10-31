"""Read the labeled provider inventory once."""

from upcloud_common import intent, inventory, run

if __name__ == "__main__":
    run(lambda: inventory(intent()))
