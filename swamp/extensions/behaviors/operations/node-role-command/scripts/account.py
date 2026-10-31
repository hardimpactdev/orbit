"""Read the enrolled account for the native role steps."""
import grp
import json
import pwd

ROLE_INPUT = None


def account(data):
    user = pwd.getpwnam(data['user'])
    return {'user': user.pw_name, 'group': grp.getgrgid(user.pw_gid).gr_name, 'home': user.pw_dir}


if __name__ == '__main__':
    print(json.dumps(account(ROLE_INPUT)))
