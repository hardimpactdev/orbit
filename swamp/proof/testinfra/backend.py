"""Testinfra transport for Incus: Testinfra 10.2.2 has LXC, but no Incus backend."""
import subprocess

from testinfra.backend.base import BaseBackend, CommandResult
from testinfra.host import Host


class IncusBackend(BaseBackend):
    NAME = 'incus'

    def run(self, command, *args, **kwargs):
        command = self.get_command(command, *args)
        done = subprocess.run(['incus', 'exec', self.hostname, '--', 'sh', '-c', command],
                              capture_output=True, text=True, timeout=kwargs.get('timeout', 60),
                              input=kwargs.get('stdin_text'))
        return CommandResult(self, done.returncode, command.encode(), done.stdout, done.stderr)


def host(name):
    backend = IncusBackend(name)
    result = Host(backend)
    backend.set_host(result)
    return result
