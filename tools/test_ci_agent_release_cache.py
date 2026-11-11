"""Exercise release cache isolation and the real delivery script without compiling Rust."""
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import textwrap
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = (ROOT / '.github/workflows/orbit-cli-release.yml').read_text()
JOBS = {name: re.search(rf'^  {name}:\n(.*?)(?=^  [\w-]+:|\Z)', WORKFLOW,
                        re.M | re.S).group(1) for name in ('agent-linux', 'agent-macos')}


def field(source, name):
    match = re.search(rf'^( +){name}: (.+)$', source, re.M)
    value = match.group(2)
    if value == '|':
        lines = source[match.end() + 1:].splitlines()
        indent = len(match.group(1)) + 1
        block = []
        for line in lines:
            if line.strip() and len(line) - len(line.lstrip()) < indent:
                break
            block.append(line)
        value = textwrap.dedent('\n'.join(block)).strip()
    return value


def cache(job):
    return next(step for step in job.split('      - ') if 'uses: actions/cache@' in step)


def render(template, context, root):
    def expression(match):
        value = match.group(1).strip()
        if value.startswith('hashFiles('):
            paths = {path for pattern in re.findall(r"'([^']+)'", value)
                     for path in root.glob(pattern) if path.is_file()}
            # Content-sensitive input for the workflow's actual globs; not an Actions runner emulator.
            return hashlib.sha256(b''.join(path.read_bytes() for path in sorted(paths))).hexdigest()
        return context[value]
    return re.sub(r'\$\{\{(.*?)\}\}', expression, template)


class AgentReleaseCacheTest(unittest.TestCase):
    def test_cache_keys_isolate_platform_compiler_targets_and_refresh_source_snapshots(self):
        with tempfile.TemporaryDirectory() as scratch:
            root = Path(scratch)
            inputs = ['apps/agent/Cargo.lock', 'apps/agent/Cargo.toml',
                      'apps/agent/crates/contract/Cargo.toml',
                      'apps/gateway/extensions/documents/agent/Cargo.toml']
            for name in inputs:
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(name)
            context = {'runner.os': 'Linux', 'runner.arch': 'X64',
                       'steps.rust.outputs.version': 'compiler-a',
                       'needs.resolve.outputs.agent_source': 'a' * 40}
            keys = []
            for name, job in JOBS.items():
                with self.subTest(job=name):
                    config = cache(job)
                    template = field(config, 'key')
                    key = render(template, context, root)
                    keys.append(key)
                    restores = field(config, 'restore-keys').splitlines()
                    prefixes = [render(p, context, root) for p in restores]
                    self.assertEqual(len(prefixes), 2)
                    for variable in ('runner.os', 'runner.arch', 'steps.rust.outputs.version'):
                        other = render(template, context | {variable: 'different'}, root)
                        self.assertNotEqual(key, other)
                        self.assertFalse(any(other.startswith(p) for p in prefixes))
                    other_source = render(template, context | {'needs.resolve.outputs.agent_source': 'b' * 40}, root)
                    self.assertNotEqual(key, other_source)
                    self.assertTrue(other_source.startswith(prefixes[0]))
                    for name in inputs:
                        path = root / name
                        original = path.read_text()
                        path.write_text(original + '\nchanged')
                        other = render(template, context, root)
                        self.assertNotEqual(key, other, name)
                        self.assertFalse(other.startswith(prefixes[0]), name)
                        self.assertTrue(other.startswith(prefixes[1]), name)
                        path.write_text(original)
            # Even with identical runner/compiler inputs, target sets must not share caches.
            self.assertNotEqual(*keys)
            for name, job in JOBS.items():
                other = keys[1 if name == 'agent-linux' else 0]
                self.assertFalse(any(other.startswith(render(p, context, root))
                                     for p in field(cache(job), 'restore-keys').splitlines()))

    def test_compiler_identity_is_read_after_toolchain_install(self):
        for job in JOBS.values():
            step = next(step for step in job.split('      - ') if 'id: rust\n' in step)
            with tempfile.TemporaryDirectory() as scratch:
                root = Path(scratch)
                rustc = root / 'rustc'
                rustc.write_text('#!/bin/sh\n[ "$*" = "--version --verbose" ] || exit 1\nprintf "%s\\n" "$COMPILER"\n')
                rustc.chmod(0o755)
                versions = []
                for compiler in ('rustc 1.99.1\ncommit-hash: aaa', 'rustc 1.99.1\ncommit-hash: bbb'):
                    output = root / 'output'
                    output.write_text('')
                    subprocess.run(['bash', '-euo', 'pipefail', '-c', field(step, 'run')],
                                   env=os.environ | {'PATH': f'{root}:{os.environ["PATH"]}',
                                                     'GITHUB_OUTPUT': str(output), 'COMPILER': compiler},
                                   check=True, timeout=10)
                    versions.append(output.read_text())
                self.assertNotEqual(*versions)
            self.assertLess(job.index('uses: dtolnay/rust-toolchain@'), job.index('id: rust\n'))
            self.assertLess(job.index('id: rust\n'), job.index('uses: actions/cache@'))

    def test_actual_build_directory_is_cached_and_both_macos_targets_are_packaged(self):
        expected = {'agent-linux': {'x86_64-unknown-linux-musl': 'linux-x86_64'},
                    'agent-macos': {'aarch64-apple-darwin': 'macos-aarch64',
                                    'x86_64-apple-darwin': 'macos-x86_64'}}
        for name, job in JOBS.items():
            with self.subTest(job=name), tempfile.TemporaryDirectory() as scratch:
                root = Path(scratch)
                agent = root / 'apps/agent'
                (agent / 'scripts').mkdir(parents=True)
                for path in ('scripts/build-delivery', 'Cargo.toml'):
                    shutil.copy2(ROOT / 'apps/agent' / path, agent / path)
                (agent / 'checkout-source').write_text('current checkout bytes')
                stub = root / 'bin'
                stub.mkdir()
                cargo = stub / 'cargo'
                cargo.write_text(textwrap.dedent('''\
                    #!/usr/bin/env python3
                    import json, os, sys
                    from pathlib import Path
                    assert sys.argv[1:4] == ['build', '--locked', '--release']
                    assert sys.argv[4] == '--target' and len(sys.argv) == 6
                    target = sys.argv[5]
                    build = Path('target') / target / 'release'
                    with open(os.environ['CARGO_CALLS'], 'a') as log:
                        log.write(json.dumps({'target': target, 'warm': (build / 'dependency').exists()}) + '\\n')
                    build.mkdir(parents=True, exist_ok=True)
                    (build / 'dependency').write_text('compiled dependency')
                    (build / 'orbit-agent').write_text(Path('checkout-source').read_text() + target)
                    '''))
                cargo.chmod(0o755)
                step = next(step for step in job.split('      - ') if 'run: scripts/build-delivery ' in step)
                paths = field(cache(job), 'path').splitlines()
                self.assertIn('~/.cargo/registry', paths)
                self.assertIn('~/.cargo/git', paths)
                build_paths = [root / path for path in paths if not path.startswith('~/')]
                self.assertEqual(len(build_paths), 1)
                calls = root / 'calls'
                temp = root / 'runner-temp'
                source = 'a' * 40
                env = os.environ | {'PATH': f'{stub}:{os.environ["PATH"]}', 'RUNNER_TEMP': str(temp),
                                    'SOURCE': source, 'CARGO_CALLS': str(calls)}
                for warm in (False, True):
                    subprocess.run(['bash', '-euo', 'pipefail', '-c', field(step, 'run')],
                                   cwd=root / field(step, 'working-directory'), env=env,
                                   check=True, capture_output=True, text=True, timeout=10)
                    delivery = temp / 'agent-delivery'
                    manifest = json.loads((delivery / 'manifest.json').read_text())
                    self.assertEqual(manifest['commit'], source)
                    self.assertEqual(set(manifest['artifacts']), set(expected[name].values()))
                    for target, platform in expected[name].items():
                        artifact = manifest['artifacts'][platform]
                        binary = delivery / artifact['path']
                        self.assertEqual(binary.read_text(), 'current checkout bytes' + target)
                        self.assertEqual(artifact['sha256'], hashlib.sha256(binary.read_bytes()).hexdigest())
                        self.assertIn(f"{artifact['sha256']}  {binary.name}", (delivery / 'SHA256SUMS').read_text())
                        for template in [field(cache(job), 'key'), *field(cache(job), 'restore-keys').splitlines()]:
                            self.assertIn(target, template)
                    if not warm:
                        # Save only the declared build cache, remove build and delivery, then restore it.
                        saved = root / 'saved-cache'
                        shutil.copytree(build_paths[0], saved)
                        shutil.rmtree(agent / 'target')
                        shutil.rmtree(delivery)
                        shutil.copytree(saved, build_paths[0])
                records = [json.loads(line) for line in calls.read_text().splitlines()]
                self.assertEqual([r['target'] for r in records], list(expected[name]) * 2)
                self.assertEqual([r['warm'] for r in records],
                                 [False] * len(expected[name]) + [True] * len(expected[name]))


if __name__ == '__main__':
    unittest.main()
