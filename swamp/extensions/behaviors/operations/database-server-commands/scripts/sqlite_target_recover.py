"""One admitted SQLite target recover action."""

from sqlite_seed_common import main, target_input, target_seed


def apply(i):
    return target_seed(target_input(i["target"], "cleanup"), preserve_installed=True)


if __name__ == "__main__":
    main(apply)
