"""State and soft checks for one native pytest case; pytest owns execution and reporting."""
import concurrent.futures
import re
import time
from pathlib import Path
from types import SimpleNamespace

import checks

PLACEHOLDER = re.compile(r'\$\{([^}]+)\}')
NODE_VALUE = re.compile(r'(gateway|app-dev|app-prod)\.(id|src|src6)$')
EXPAND = {'probe': ('from', 'proto', 'port', 'family'), 'ufw_rule': ('proto', 'family')}


class Proof:
    def __init__(self, fleet, evidence, target, negative=None):
        self.fleet, self.target, self.evidence = fleet, target, Path(evidence)
        self.spec = SimpleNamespace(baseline=['ufw', 'firewall_records', 'operations', 'listeners'])
        self.names, self.rules, self.baseline, self.sources = {}, {}, {}, {}
        self.log, self.failures, self.calls = [], [], 0
        self.acted, self.started = time.time(), time.monotonic()
        self.shimmed, self.background, self.negative = set(), [], negative
        self.section = 'setup'
        self.evidence.mkdir(parents=True, exist_ok=True)

    def cli(self, args, form='json', node=None):
        return self.keep(self.fleet.cli(args, form=form, node=node), 'json' if form == 'json' else 'txt')

    def cli_tty(self, args, script):
        return self.keep(self.fleet.cli_tty(args, script), 'txt')

    def keep(self, result, suffix):
        self.calls += 1
        (self.evidence / f'cli-{self.calls}.{suffix}').write_text(result['stdout'])
        (self.evidence / f'cli-{self.calls}.stderr').write_text(result['stderr'])
        return result

    def evidence_text(self, name, text):
        (self.evidence / name).write_text(text)

    def sleep(self, seconds):
        if seconds > 0:
            self.fleet.sh(self.target, f'sleep {int(seconds) + 1}', timeout=int(seconds) + 30)

    def value(self, name):
        if name in self.names:
            return self.names[name]
        found = NODE_VALUE.fullmatch(name)
        if not found:
            raise checks.Infra(f'unbound value {name}')
        node, kind = found.groups()
        if kind == 'id':
            return self.fleet.nodes[node]['node_id']
        key = (node, 6 if kind == 'src6' else 4)
        if key not in self.sources:
            address = self.fleet.nodes[self.target]['bridge6' if key[1] == 6 else 'bridge']
            words = self.fleet.sh(node, f'ip -{key[1]} route get {address}').stdout.split()
            if 'src' not in words:
                raise checks.Infra(f'no source route from {node} to {self.target}')
            self.sources[key] = words[words.index('src') + 1]
        return self.sources[key]

    def resolve(self, value):
        if isinstance(value, str):
            whole = PLACEHOLDER.fullmatch(value)
            return self.value(whole[1]) if whole else PLACEHOLDER.sub(lambda m: str(self.value(m[1])), value)
        if isinstance(value, dict):
            return {key: self.resolve(item) for key, item in value.items()}
        if isinstance(value, list):
            return [self.resolve(item) for item in value]
        return value

    def act(self, *providers):
        self.section = 'act'
        self.baseline = {provider: checks.snapshot(self, provider) for provider in providers}

    def observe(self):
        self.acted = time.time()
        if self.negative:
            # A broken act or setup must never count as a successful negative proof.
            assert not self.failures, '\n'.join(self.failures)
            for name, args in self.negative['do']:
                failed = getattr(checks, name)(self, self.resolve(args))
                assert not failed, '\n'.join(failed)
        self.section = 'expect'

    def check(self, name, args):
        began = time.monotonic()
        aliases = {'source': 'from', 'target': 'to', 'name': 'as', 'excluding': 'except'}
        args = self.resolve({aliases.get(key, key): value for key, value in args.items()})
        expanded = [args]
        for key in EXPAND.get(name, ()):
            expanded = [{**item, key: value} for item in expanded
                        for value in (item[key] if isinstance(item.get(key), list) else [item.get(key)])]
        function = getattr(checks, name)
        if name == 'probe' and len(expanded) > 1:
            with concurrent.futures.ThreadPoolExecutor(len(expanded)) as pool:
                failures = [failure for group in pool.map(lambda item: function(self, item), expanded) for failure in group]
        else:
            failures = [failure for item in expanded for failure in function(self, item)]
        self.log.append({'section': self.section, 'check': name, 'failed': failures, 'seconds': time.monotonic() - began})
        if failures and self.section == 'setup':
            raise checks.Unrun('\n'.join(failures))
        self.failures.extend(f'{self.section} {failure}' for failure in failures)

    def finish(self):
        if self.negative:
            missing = [name for name in self.negative['must_fail']
                       if not any(f' {name}:' in failure for failure in self.failures)]
            assert self.negative['must_fail'] and not missing, f'negative assertions did not fail: {missing}'
        else:
            assert not self.failures, '\n'.join(self.failures)

    def command(self, **args):
        self.check('cli', args)

    def listener(self, **args):
        self.check('listener', args)

    def ufw_rule(self, **args):
        self.check('ufw_rule', args)

    def fault(self, **args):
        self.check('fault', args)

    def busy_node(self, **args):
        self.check('busy_node', args)

    def ipv6_addresses(self, **args):
        self.check('ipv6_addresses', args)

    def probe(self, **args):
        self.check('probe', args)

    def ufw(self, **args):
        self.check('ufw', args)

    def record(self, **args):
        self.check('record', args)

    def doctor(self, **args):
        self.check('doctor', args)

    def unchanged(self, **args):
        self.check('unchanged', args)

    def ufw_delete(self, **args):
        self.check('ufw_delete', args)

    def agent_report(self, **args):
        self.check('agent_report', args)

    def node_down(self, **args):
        self.check('node_down', args)

    def node_access_remove(self, **args):
        self.check('node_access_remove', args)
