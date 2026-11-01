"""One bounded external-provider state poll; requests are never retried."""
import json
import os
import sys
import time
import urllib.error

from upcloud_api import poll_read, public_server


def wait_started(provider_id):
    while True:
        server = poll_read('server/' + provider_id)['server']
        state = server['state']
        if server['uuid'] != provider_id:
            raise SystemExit('task_vm.provider_identity_invalid: start observation returned another server')
        if state == 'started':
            return server
        if state not in ('stopped', 'maintenance'):
            raise SystemExit('task_vm.not_settled: provider reached an unsupported start state')
        time.sleep(3)


def main():
    try:
        if sys.argv[1] != 'wait-started':
            raise ValueError('unsupported stage')
        choice = json.loads(os.environ['ORBIT_CLOUD_CHOICE'])
        print(json.dumps({'server': public_server(wait_started(choice['provider_id']))}))
    except urllib.error.HTTPError as error:
        print('task_vm.provider_server_missing' if error.code == 404 else 'task_vm.upcloud_failed', file=sys.stderr)
        return 1
    except (OSError, ValueError, KeyError, IndexError, TypeError, urllib.error.URLError):
        print('task_vm.upcloud_failed', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
