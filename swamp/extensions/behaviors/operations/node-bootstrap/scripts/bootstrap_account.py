"""Create the admitted managed account without changing an existing home."""

import json
import pwd
import subprocess

BOOTSTRAP_INPUT = None


def prepare(data):
    user = data['user']
    try:
        account = pwd.getpwnam(user)
    except KeyError:
        subprocess.run(['/usr/sbin/useradd', '--create-home', '--shell', '/bin/bash', '--', user],
                       stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                       stderr=subprocess.DEVNULL, check=True)
        account = pwd.getpwnam(user)
    return {'account_prepared': True, 'user': account.pw_name, 'home': account.pw_dir}


if __name__ == '__main__':
    print(json.dumps(prepare(BOOTSTRAP_INPUT)))
