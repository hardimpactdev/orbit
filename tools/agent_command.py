"""Local build adapters call the checkout's native agent with typed input and its exact digest."""
import hashlib
import json
from pathlib import Path
import subprocess


class CommandError(RuntimeError):
    pass


class Refused(CommandError):
    def __init__(self, code, message, details, log):
        super().__init__(f'{code}: {message}')
        self.code, self.details = code, details
        self.log = log


def run(agent, command, payload, seconds):
    try:
        digest = hashlib.sha256(Path(agent).read_bytes()).hexdigest()
    except OSError as error:
        raise CommandError('Cannot read the source-built agent. Build apps/agent with cargo build --release --locked.') from error
    try:
        completed = subprocess.run([str(agent), 'command', command],
                                   input=json.dumps({'agent_sha256': digest, 'deadline_ms': seconds * 1000,
                                                     'input': payload}).encode(),
                                   capture_output=True, timeout=seconds + 10)
    except subprocess.TimeoutExpired as error:
        raise CommandError(f'{command} exceeded its deadline; inspect the host before retrying.') from error
    except OSError as error:
        raise CommandError(f'Cannot execute the source-built agent: {error}') from error
    log = completed.stderr.decode(errors='replace')
    try:
        result = json.loads(completed.stdout)
        if completed.returncode != 0 or not isinstance(result, dict):
            raise ValueError('Agent process failed')
        if result.get('ok') is False:
            error = result['error']
            code, message, details = error['code'], error['message'], error['details']
            if not isinstance(code, str) or not isinstance(message, str) or not isinstance(details, dict):
                raise ValueError('Invalid refusal')
            raise Refused(code, message, details, log)
        if result.get('ok') is not True or not isinstance(result['data'], dict):
            raise ValueError('Invalid success result')
        return result['data'], log
    except (KeyError, TypeError, ValueError) as error:
        raise CommandError(f'{command} ended without a valid agent result:\n{log[-4000:]}') from error
