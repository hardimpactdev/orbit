"""Serve committed fixture payloads inside the owned topology Gateway; no remote origins."""
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

CHECKOUT = Path('/home/orbit/orbit')
REPOSITORIES = Path('/srv/swamp-proof/repositories')


def run(argv, *, env=None, source=None):
    result = subprocess.run(argv, input=source, env=env, capture_output=True, timeout=60, check=False)
    if result.returncode:
        raise RuntimeError('proof.repository_command_failed')
    return result.stdout.decode().strip()


def validate_payload(payload, destination):
    if not payload.is_dir() or not any(payload.iterdir()) or any(path.name == '.git' for path in payload.rglob('*')):
        raise ValueError('proof.repository_payload_invalid')
    if destination.exists():
        raise ValueError('proof.repository_already_exists')


def materialize(payload, destination):
    """Build one deterministic main commit from public files, including executable bits and links."""
    validate_payload(payload, destination)
    env = {key: value for key, value in os.environ.items() if not key.startswith('GIT_')}
    env.update(GIT_CONFIG_NOSYSTEM='1', GIT_CONFIG_GLOBAL='/dev/null', GIT_AUTHOR_NAME='Swamp proof',
               GIT_AUTHOR_EMAIL='proof@invalid', GIT_COMMITTER_NAME='Swamp proof', GIT_COMMITTER_EMAIL='proof@invalid',
               GIT_AUTHOR_DATE='2000-01-01T00:00:00+00:00', GIT_COMMITTER_DATE='2000-01-01T00:00:00+00:00')
    def git(folder, *argv, source=None):
        return run(['git', '-c', 'core.hooksPath=/dev/null', '-C', str(folder), *argv], env=env, source=source)
    with tempfile.TemporaryDirectory(prefix='swamp-proof-payload-') as tmp:
        stage = Path(tmp) / 'source'
        shutil.copytree(payload, stage, symlinks=True)
        files = sorted(path for path in stage.rglob('*') if path.is_file() or path.is_symlink())
        git(stage, 'init', '--quiet', '--initial-branch=main')
        # Attributes and clean filters must not rewrite the committed fixture bytes.
        for path in files:
            linked = path.is_symlink()
            body = os.fsencode(os.readlink(path)) if linked else path.read_bytes()
            mode = '120000' if linked else '100755' if path.stat().st_mode & 0o111 else '100644'
            blob = git(stage, 'hash-object', '-w', '--no-filters', '--stdin', source=body)
            git(stage, 'update-index', '--add', '--cacheinfo', mode, blob, path.relative_to(stage).as_posix())
        git(stage, 'commit', '--quiet', '-m', 'Proof fixture')
        commit = git(stage, 'rev-parse', 'HEAD')
        destination.parent.mkdir(parents=True, exist_ok=True)
        git(destination.parent, 'init', '--bare', '--quiet', '--initial-branch=main', str(destination))
        git(stage, 'push', '--quiet', str(destination), 'HEAD:refs/heads/main')
        git(destination, 'config', 'http.receivepack', 'false')
    return commit


def create(kind, tag, site, variant=None):
    if kind != 'seed' and not re.fullmatch(r'[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*', kind):
        raise ValueError('proof.repository_kind_invalid')
    if not re.fullmatch(r'[a-zA-Z0-9-]{1,64}', tag) or site != 'repositories.orbit':
        raise ValueError('proof.repository_target_invalid')
    if variant is not None and not re.fullmatch(r'[a-zA-Z0-9_-]{1,64}', variant):
        raise ValueError('proof.repository_variant_invalid')
    folder = CHECKOUT / 'swamp/proof' / kind
    variant_payload = folder / variant / 'repository' if variant is not None else None
    payload = variant_payload if variant_payload is not None and variant_payload.is_dir() else folder / 'repository'
    name = kind + ('-' + variant if variant is not None else '') + '.git'
    relative = Path('seed.git') if kind == 'seed' else Path(tag) / name
    destination = REPOSITORIES / relative
    commit = materialize(payload, destination)
    # Payloads are public test code. The existing topology Caddy backend reads them.
    for path in [REPOSITORIES.parent, REPOSITORIES, destination.parent, destination, *destination.rglob('*')]:
        if not path.is_symlink():
            path.chmod(0o755 if path.is_dir() else 0o644)
    return {'url': 'https://' + site + '/' + relative.as_posix(), 'commit': commit, 'branch': 'main'}


def main():
    try:
        if os.geteuid() != 0 or len(sys.argv) not in (4, 5) or not (CHECKOUT / 'bin/e2e-topology').is_file():
            raise ValueError('proof.repository_owned_gateway_required')
        print(json.dumps(create(*sys.argv[1:])))
        return 0
    except (ValueError, OSError, RuntimeError, subprocess.TimeoutExpired) as error:
        code = str(error)
        print(code if re.fullmatch(r'proof\.repository_[a-z_]+', code) else 'proof.repository_prepare_failed', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
