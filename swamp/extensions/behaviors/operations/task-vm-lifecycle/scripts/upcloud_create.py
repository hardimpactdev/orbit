"""One provider request per native step. Host-key material comes only from the vault."""
import json
import os
import sys
import urllib.error

from upcloud import api, public_server


def execute(intent, stage):
    if stage in ('list', 'discover'):
        value = api('GET', 'server?label=orbit-task-vm')
        return {'servers': {'server': [public_server(row) for row in value['servers']['server']]}}
    if stage == 'plans':
        value = api('GET', 'plan')
        return {'plans': {'plan': [{key: row[key] for key in ('name', 'storage_tier') if key in row} for row in value['plans']['plan']]}}
    choice = json.loads(os.environ['ORBIT_CLOUD_CHOICE'])
    if stage == 'create':
        if choice['provider_id'] is not None:
            return {'provider_id': choice['provider_id']}
        bootstrap = json.loads(os.environ['ORBIT_PROVIDER_BOOTSTRAP'])
        if bootstrap['public'] != intent['bootstrap_host_key'] or not bootstrap['private'].startswith('-----BEGIN OPENSSH PRIVATE KEY-----'):
            raise ValueError('bootstrap mismatch')
        cloud = intent['upcloud']
        disk = {'action': 'clone', 'storage': cloud['source_template'], 'title': intent['name'] + '-disk', 'size': cloud['disk_gb']}
        if choice['storage_tier'] is not None:
            disk['tier'] = choice['storage_tier']
        user = {'name': 'orbit', 'lock_passwd': True, 'shell': '/bin/bash', 'sudo': 'ALL=(ALL) NOPASSWD:ALL',
                'ssh_authorized_keys': [intent['ssh_identity']['public_key']]}
        config = {'users': [user], 'disable_root': True, 'ssh_deletekeys': True, 'ssh_genkeytypes': [],
                  'ssh_keys': {'ed25519_private': bootstrap['private'], 'ed25519_public': bootstrap['public']}}
        value = api('POST', 'server', {'server': {
            'zone': cloud['zone'], 'title': intent['name'], 'hostname': intent['name'], 'plan': cloud['plan'],
            'metadata': 'yes', 'firewall': 'off', 'remote_access_enabled': 'no',
            'labels': {'label': [{'key': 'orbit-task-vm', 'value': str(intent['task_vm_id'])},
                                 {'key': 'orbit-task-vm-image', 'value': cloud['source_template']}]},
            'networking': {'interfaces': {'interface': [{'type': 'public', 'ip_addresses': {'ip_address': [{'family': 'IPv4'}]}}]}},
            'storage_devices': {'storage_device': [disk]},
            'login_user': {'username': 'root', 'ssh_keys': {'ssh_key': [intent['ssh_identity']['public_key']]}},
            'user_data': '#cloud-config\n' + json.dumps(config) + '\n',
        }})
        return {'provider_id': value['server']['uuid']}
    provider_id = choice['provider_id']
    if provider_id is None:
        raise ValueError('missing VM')
    if stage == 'start':
        if choice['state'] == 'stopped':
            api('POST', 'server/' + provider_id + '/start', {'server': {}})
        elif choice['state'] != 'started':
            raise ValueError('unsettled')
        return {'provider_id': provider_id}
    if stage == 'observe':
        value = api('GET', 'server/' + provider_id)
        return {'server': public_server(value['server'])}
    raise ValueError('unsupported stage')


def main():
    try:
        print(json.dumps(execute(json.loads(os.environ['ORBIT_PROVIDER_INTENT']), sys.argv[1])))
    except (OSError, ValueError, KeyError, IndexError, TypeError, urllib.error.URLError):
        print('task_vm.upcloud_failed', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
