"""Check release-rendered grants with pinned Swamp's serve startup validator."""
import argparse
import importlib.util
import json
import os
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def load_module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


runtime = load_module('swamp_validation', ROOT / 'bin/validate-swamp-workflows.py')


def render_grants(root, destination):
    """Use the release renderer, including raw serve conditions, without publishing live grants."""
    inputs = {root / 'config/gateway-workflow-grants.json', root / 'config/serve-grants.json'}
    discovered = set((root / 'config').glob('*grants*.json'))
    if discovered != inputs:
        raise ValueError(f'Grant inputs need complete release-renderer coverage: {sorted(str(p) for p in discovered ^ inputs)}')
    renderer = ROOT.parent / 'apps/gateway/bin/render-swamp-grants'
    files = json.loads(subprocess.run(['php', str(renderer), str(root)], capture_output=True, text=True, check=True, timeout=30).stdout)
    count = 0
    for name, content in files.items():
        path = destination / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content)
        count += len(json.loads(content)['grants'])
    return len(files), count


def validate_grants(root, binary, version, inherited):
    with tempfile.TemporaryDirectory(prefix='swamp-grants-isolated-') as scratch:
        repo = Path(scratch)
        # No workflows, vaults, datastore, serve config or credentials from the checkout.
        (repo / '.swamp.yaml').write_text(f'swampVersion: {version}\ntelemetryDisabled: true\n')
        files, grants = render_grants(root, repo)
        account = repo / 'account'
        account.mkdir()
        result = subprocess.run([
            str(binary), 'serve', 'check-config', '--repo-dir', str(repo),
            '--auth-mode', 'token', '--admins', 'user:orbit-grant-ci', '--json',
        ], env=runtime.native_env(str(account), inherited), capture_output=True, text=True, timeout=60)
        try:
            report = json.loads(result.stdout)
        except ValueError:
            report = {}
        if (result.returncode or report.get('passed') is not True or report.get('wouldStart') is not True
                or report.get('grantFilesChecked') != files):
            raise ValueError(f'Pinned serve refused rendered grants (expected {files} files):\n{result.stdout}\n{result.stderr}')
        return {'runtime': version, 'grant_files': files, 'grants': grants, 'passed': True, 'wouldStart': True}


def main():
    parser = argparse.ArgumentParser(__doc__)
    parser.add_argument('--cache', type=Path, required=True)
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    if args.report:
        args.report.unlink(missing_ok=True)
    binary, version = runtime.pinned_runtime(args.cache, ROOT)
    report = validate_grants(ROOT, binary, version, os.environ)
    if args.report:
        args.report.write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report))


if __name__ == '__main__':
    main()
