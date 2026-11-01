"""One admitted SQLite target cleanup action."""

from sqlite_seed_common import main, target_input, target_seed


def apply(i):
    return target_seed(target_input(i["target"], "cleanup"))


if __name__ == "__main__":
    main(apply)
