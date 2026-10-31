"""Check the admitted non-root account and group through the target NSS database."""

import grp
import pwd
import sys


def validate(user, home, shell, group):
    account = pwd.getpwnam(user)
    selected_group = grp.getgrnam(group)
    return (
        account.pw_name == user
        and account.pw_uid > 0
        and account.pw_dir == home
        and account.pw_shell == shell
        and selected_group.gr_name == group
    )


if __name__ == "__main__":
    try:
        valid = validate(*sys.argv[1:])
    except (KeyError, OSError, TypeError):
        valid = False
    if not valid:
        print('{"error_code":"schedule.target_unavailable"}')
        raise SystemExit(1)
    print('{"ready":true}')
