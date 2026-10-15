"""Image-only phases, appended to the shared recipe helpers. Never runs workspace setup or a package cache fill."""

import traceback

IMAGE_LOG = None


def command_output(result):
    if IMAGE_LOG is not None:
        print('Command: ' + str(result.args[0]) + '; exit code: ' + str(result.returncode), file=IMAGE_LOG)
        print(result.stdout, end='', file=IMAGE_LOG)
        print(result.stderr, end='', file=IMAGE_LOG)
        IMAGE_LOG.flush()


def image_packages(payload):
    snapshot = payload['snapshot']
    source = (f'Types: deb\nURIs: https://snapshot.ubuntu.com/ubuntu/{snapshot}/\n'
              'Suites: resolute resolute-updates resolute-security\nComponents: main universe restricted multiverse\n'
              'Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg\nCheck-Valid-Until: no\n')
    publish('/etc/apt/sources.list.d/ubuntu.sources', source.encode())
    options = ['-o', 'Dir::Etc::sourcelist=sources.list.d/ubuntu.sources', '-o', 'Dir::Etc::sourceparts=-',
               '-o', 'DPkg::Lock::Timeout=300']
    run(['apt-get', 'update', *options])
    environment = dict(os.environ, DEBIAN_FRONTEND='noninteractive', NEEDRESTART_SUSPEND='1')
    installed = packages()
    ubuntu = [name + '=' + pin['version'] for name, pin in payload['tools'].items()
              if pin['source'] == 'ubuntu' and installed.get(name) != pin['version']]
    if POLICY.exists() or POLICY.is_symlink():
        raise RecipeFailure()
    publish(POLICY, b'#!/bin/sh\nexit 101\n', 0o755)
    try:
        if payload['profile'] == 'base':
            run(['apt-get', 'full-upgrade', '--yes', *options], env=environment)
        # Install the recipe's curl and CA packages before fetching third-party artifacts on a stock image.
        if ubuntu:
            run(['apt-get', 'install', '--yes', '--no-install-recommends', '--allow-downgrades',
                 *options, '--', *ubuntu], env=environment)
        with tempfile.TemporaryDirectory(prefix='orbit-image-packages-') as directory:
            work = Path(directory)
            debs = []
            for name, pin in payload['tools'].items():
                if pin['source'] != 'artifact' or not pin['artifacts'][payload['architecture']]['url'].endswith('.deb'):
                    continue
                if installed.get(name) == pin['version']:
                    continue
                deb = download(pin, payload['architecture'], work / (name + '.deb'))
                fields = run(['dpkg-deb', '-f', str(deb), 'Package', 'Version', 'Architecture']).stdout.splitlines()
                metadata = [line.split(': ', 1)[-1] for line in fields]
                if metadata[:2] != [name, pin['version']] or metadata[2:] not in ([payload['architecture']], ['all']):
                    raise RecipeFailure()
                debs.append(str(deb))
            if debs:
                run(['apt-get', 'install', '--yes', '--no-install-recommends', '--allow-downgrades',
                     *options, '--', *debs], env=environment)
    finally:
        POLICY.unlink()
    # PCOV loads in the CLI and never in PHP-FPM (ADR 0215).
    for version in sorted(payload.get('php') or {}):
        run(['phpenmod', '-v', version, '-s', 'cli', 'pcov'])
        run(['phpdismod', '-v', version, '-s', 'fpm', 'pcov'])
    # dnsmasq and Caddy are configured at enrollment, not while baking the disk.
    for service in ('ssh', 'docker', 'containerd'):
        if run(['systemctl', 'show', '-p', 'LoadState', '--value', service], required=False).stdout.strip() == 'loaded':
            run(['systemctl', 'enable', service])


def image_javascript(payload):
    tools = payload['tools']
    names = ['node', 'vp-cli', 'vp', 'pnpm', 'bun']
    if not set(names) & set(tools):
        return
    os.environ['SUDO_USER'] = 'orbit'
    selected = {name: tools[name] for name in names if name in tools}
    observed = inspect(selected)
    if all(observed[name] == pin['version'] for name, pin in selected.items()):
        return
    account = pwd.getpwnam('orbit')
    scope = Path(account.pw_dir) / '.local/share/vite-plus'
    for directory in (Path(account.pw_dir) / '.local', Path(account.pw_dir) / '.local/share', scope):
        safe_directory(directory, account.pw_uid, account.pw_gid)
    with tempfile.TemporaryDirectory(prefix='orbit-image-js-') as directory:
        work = Path(directory)
        downloads = {name: download(tools[name], payload['architecture'], work / (name + '.archive')) for name in names if name in tools}
        # The standalone, pinned vp dispatcher needs no first-run installer. The recipe's JS distribution
        # is kept beside it; no package-manager dependency resolution or Project package install runs here.
        if 'vp' in downloads and not (scope / 'current/node_modules/vite-plus').exists():
            unpacked = extract(downloads['vp'], work / 'seed-vp')
            current = scope / 'current/node_modules'
            current.mkdir(parents=True)
            shutil.copytree(unpacked / 'package', current / 'vite-plus')
            for parent, directories, files in os.walk(scope):
                os.chown(parent, account.pw_uid, account.pw_gid)
                for entry in directories + files:
                    os.chown(Path(parent) / entry, account.pw_uid, account.pw_gid, follow_symlinks=False)
            # A fresh image runs the standalone dispatcher; production's existing JS dependency closure
            # is not a prerequisite for installing its exact JS distribution in this image.
            active = current / 'vite-plus'
            moved = scope / 'seed-vite-plus'
            active.rename(moved)
            active.symlink_to(moved, target_is_directory=True)
            os.chown(active, account.pw_uid, account.pw_gid, follow_symlinks=False)
        # Avoid the production helper's dependency-closure migration for a fresh standalone image.
        javascript_tools = {name: pin for name, pin in tools.items() if name in names and name != 'vp'}
        install_javascript(javascript_tools, {name: path for name, path in downloads.items() if name != 'vp'}, work, account)
        runtime = safe_directory(scope / 'recipe', account.pw_uid, account.pw_gid)
        if 'vp' in downloads:
            target = runtime / ('vp-' + hashlib.sha256(downloads['vp'].read_bytes()).hexdigest())
            if not target.exists():
                shutil.copytree(scope / 'current/node_modules/vite-plus', target)
                for parent, directories, files in os.walk(target):
                    os.chown(parent, account.pw_uid, account.pw_gid)
                    for entry in directories + files:
                        os.chown(Path(parent) / entry, account.pw_uid, account.pw_gid, follow_symlinks=False)
            package_link(runtime, 'vp', target, account.pw_uid, account.pw_gid)


def image_rust(payload):
    names = ('rust', 'cargo', 'rust-std', 'clippy', 'rustfmt')
    if not set(names) & set(payload['tools']):
        return
    pins = {name: payload['tools'][name]['artifacts'][payload['architecture']] for name in names if name in payload['tools']}
    receipt = Path('/opt/orbit/rust/.recipe-artifacts.json')
    if json_file(receipt) == pins:
        return
    with tempfile.TemporaryDirectory(prefix='orbit-image-rust-') as directory:
        work = Path(directory)
        for name in ('rust', 'cargo', 'rust-std', 'clippy', 'rustfmt'):
            if name not in payload['tools']:
                continue
            archive = download(payload['tools'][name], payload['architecture'], work / (name + '.archive'))
            unpacked = extract(archive, work / name)
            installer = next(unpacked.glob('*/install.sh'))
            run(['bash', str(installer), '--prefix=/opt/orbit/rust', '--disable-ldconfig'])
    publish(receipt, json.dumps(pins, sort_keys=True).encode())
    cargo = Path('/home/orbit/.cargo/bin')
    cargo.mkdir(parents=True, exist_ok=True)
    account = pwd.getpwnam('orbit')
    os.chown(cargo.parent, account.pw_uid, account.pw_gid)
    os.chown(cargo, account.pw_uid, account.pw_gid)
    for binary in Path('/opt/orbit/rust/bin').iterdir():
        for target in (cargo / binary.name, Path('/usr/local/bin') / binary.name):
            target.unlink(missing_ok=True)
            target.symlink_to(binary)


def image_agent(payload):
    pin = payload['tools'].get('orbit-agent')
    if pin is None:
        return
    artifact = pin['artifacts'][payload['architecture']]
    destination = Path('/usr/local/bin/orbit-agent')
    if destination.is_file():
        algorithm = 'sha256' if 'sha256' in artifact else 'sha512'
        with destination.open('rb') as stream:
            if hashlib.file_digest(stream, algorithm).hexdigest() == artifact[algorithm]:
                return
    with tempfile.TemporaryDirectory(prefix='orbit-image-agent-') as directory:
        artifact = download(pin, payload['architecture'], Path(directory) / 'orbit-agent')
        publish('/usr/local/bin/orbit-agent', artifact.read_bytes(), 0o755)


def image_verify(payload):
    marker = json.loads(Path('/etc/orbit-machine-recipe.json').read_text())
    if marker != {'identity': payload['identity'], 'profile': payload['profile']}:
        raise RecipeFailure()
    selected = {name: pin for name, pin in payload['tools'].items()
                if name not in ('rust', 'cargo', 'rust-std', 'clippy', 'rustfmt', 'orbit-agent', 'pi')}
    os.environ['SUDO_USER'] = 'orbit'
    observed = inspect(selected)
    if any(observed[name] != pin['version'] for name, pin in selected.items()):
        raise RecipeFailure()
    if 'pnpm' in selected:
        result = run(['sudo', '-u', 'orbit', '-H', 'env', '-C', '/home/orbit',
                      '/usr/local/bin/pnpm', '--version'])
        if result.stdout.strip() != selected['pnpm']['version']:
            raise RecipeFailure()
    for name, path in [('pi', '/home/orbit/.local/bin/pi-server'), ('orbit-agent', '/usr/local/bin/orbit-agent')]:
        if name in payload['tools']:
            artifact = payload['tools'][name]['artifacts'][payload['architecture']]
            algorithm = 'sha256' if 'sha256' in artifact else 'sha512'
            with open(path, 'rb') as stream:
                if hashlib.file_digest(stream, algorithm).hexdigest() != artifact[algorithm]:
                    raise RecipeFailure()
    rust_pins = {name: payload['tools'][name]['artifacts'][payload['architecture']]
                 for name in ('rust', 'cargo', 'rust-std', 'clippy', 'rustfmt') if name in payload['tools']}
    if rust_pins and json_file(Path('/opt/orbit/rust/.recipe-artifacts.json')) != rust_pins:
        raise RecipeFailure()
    for name, binary in [('rust', 'rustc'), ('cargo', 'cargo')]:
        if name in payload['tools'] and binary_version('/opt/orbit/rust/bin/' + binary) != payload['tools'][name]['version']:
            raise RecipeFailure()
    for version, expected in sorted((payload.get('php') or {}).items()):
        if cli_modules(version) != expected:
            raise RecipeFailure()
    for path in ('/var/lib/orbit/package-cache', '/opt/orbit/package-cache', '/home/orbit/orbit', '/home/orbit/.orbit/gateway.sqlite'):
        if Path(path).exists():
            raise RecipeFailure()


def image_main():
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    global IMAGE_LOG
    phase, document = sys.argv[1:]
    path = None
    try:
        payload = json.loads(document)
        profile = payload['profile']
        if not re.fullmatch(r'[a-z-]+', profile) or phase not in ('packages', 'javascript', 'rust', 'agent', 'verify'):
            raise RecipeFailure('Invalid image phase')
        directory = Path('/var/log/orbit/recipe-images')
        directory.mkdir(parents=True, mode=0o700, exist_ok=True)
        directory.chmod(0o700)
        path = directory / (profile + '-' + phase + '.log')
        IMAGE_LOG = os.fdopen(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_APPEND, 0o600), 'w')
        os.chmod(path, 0o600)
        print('Recipe image phase: ' + profile + ':' + phase, file=IMAGE_LOG, flush=True)
        platform_check(payload['architecture'])
        {'packages': image_packages, 'javascript': image_javascript, 'rust': image_rust,
         'agent': image_agent, 'verify': image_verify}[phase](payload)
        print('{"ok":true}')
        return 0
    except Exception:
        if IMAGE_LOG is not None:
            traceback.print_exc(file=IMAGE_LOG)
            IMAGE_LOG.flush()
            print('\n'.join(path.read_text(errors='replace').splitlines()[-40:]), file=sys.stderr)
        else:
            traceback.print_exc(file=sys.stderr)
        print('recipe-image-stage:' + phase)
        return 1
    finally:
        if IMAGE_LOG is not None:
            IMAGE_LOG.close()
            IMAGE_LOG = None


if __name__ == '__main__':
    sys.exit(image_main())
