"""Image-only phases, appended to the shared recipe helpers. Never runs workspace setup or a package cache fill."""

import traceback

IMAGE_LOG = None
IMAGE_APT = Path('/var/tmp/orbit-image-apt')


def command_output(result):
    if IMAGE_LOG is not None:
        print('Command: ' + str(result.args[0]) + '; exit code: ' + str(result.returncode), file=IMAGE_LOG)
        print(result.stdout, end='', file=IMAGE_LOG)
        print(result.stderr, end='', file=IMAGE_LOG)
        IMAGE_LOG.flush()


def image_apt_source(payload):
    image_apt_setup(payload, IMAGE_APT)
    with image_apt_environment(payload):
        run(['apt-get', 'update'])


def image_apt_setup(payload, directory):
    directory.mkdir(parents=True, mode=0o755, exist_ok=True)
    publish(directory / 'ubuntu.sources', snapshot_source(payload['snapshot']).encode())
    publish(directory / 'apt.conf', image_apt_configuration(directory).encode())


def image_apt_configuration(directory):
    # Exact recipe pins may require a non-candidate dependency, even one just upgraded by full-upgrade.
    return ('Dir::Etc::parts "";\nDir::Etc::main "";\n'
            f'Dir::Etc::sourcelist "{directory}/ubuntu.sources";\nDir::Etc::sourceparts "";\n'
            'APT::Solver::Strict-Pinning "false";\nAPT::Get::allow-downgrades "true";\n'
            'APT::Get::List-Cleanup "false";\n'
            'APT::Update::Error-Mode "any";\nDPkg::Lock::Timeout "300";\n')


@contextmanager
def image_apt_environment(payload, directory=None, *, phase='apt-source'):
    directory = IMAGE_APT if directory is None else directory
    config = directory / 'apt.conf'
    try:
        matches = (config.read_text() == image_apt_configuration(directory)
                   and (directory / 'ubuntu.sources').read_text() == snapshot_source(payload['snapshot']))
    except FileNotFoundError:
        matches = False
    if not matches:
        detail = ('Verification snapshot configuration is missing or mismatched' if phase == 'verify'
                  else 'apt-source must run before packages: snapshot configuration is missing or mismatched')
        raise RecipeFailure(phase, detail=detail)
    previous = os.environ.get('APT_CONFIG')
    os.environ['APT_CONFIG'] = str(config)
    try:
        yield
    finally:
        if previous is None:
            os.environ.pop('APT_CONFIG', None)
        else:
            os.environ['APT_CONFIG'] = previous


def image_packages(payload):
    # APT_CONFIG crosses the PHP installer's process boundary too. No image install reads stock/live indexes.
    with image_apt_environment(payload):
        image_install_packages(payload)


def image_install_packages(payload):
    environment = dict(os.environ, DEBIAN_FRONTEND='noninteractive', NEEDRESTART_SUSPEND='1')
    if POLICY.exists() or POLICY.is_symlink():
        raise RecipeFailure()
    publish(POLICY, b'#!/bin/sh\nexit 101\n', 0o755)
    try:
        installed = packages()
        tools = {name: pin for name, pin in payload['tools'].items() if pin['source'] == 'ubuntu' or name in git_packages(payload)}
        check_git_siblings(payload)
        assessment = assess(tools, {name: installed.get(pin.get('package', name)) for name, pin in tools.items()}, git_names=git_packages(payload))
        supported(assessment)
        configure_git(payload, apply=True)
        if payload['profile'] == 'base':
            run(['apt-get', 'full-upgrade', '--yes'], env=environment)
        # full-upgrade may have installed a newer same-release version; inspect again under the same configuration.
        installed = packages()
        assessment = assess(tools, {name: installed.get(pin.get('package', name)) for name, pin in tools.items()}, git_names=git_packages(payload))
        supported(assessment)
        ubuntu = [pin['package'] + '=' + pin['version'] for name, pin in tools.items() if pin['source'] == 'ubuntu' and assessment[name]['changed']]
        # Install the recipe's curl and CA packages before fetching third-party artifacts on a stock image.
        if ubuntu:
            run(['apt-get', 'install', '--yes', '--no-install-recommends', '--no-remove',
                 '--', *ubuntu], env=environment)
        with tempfile.TemporaryDirectory(prefix='orbit-image-packages-') as directory:
            work = Path(directory)
            debs = []
            php = php_packages(payload)
            for name, pin in payload['tools'].items():
                if pin['source'] != 'artifact' or not pin['artifacts'][payload['architecture']]['url'].endswith('.deb'):
                    continue
                if name in php:
                    continue
                if installed.get(name) == pin['version']:
                    continue
                deb = download_deb(name, pin, payload, work / (name + '.deb'))
                fields = run(['dpkg-deb', '-f', str(deb), 'Package', 'Version', 'Architecture']).stdout.splitlines()
                metadata = [line.split(': ', 1)[-1] for line in fields]
                if metadata[:2] != [name, pin['version']] or metadata[2:] not in ([payload['architecture']], ['all']):
                    raise RecipeFailure()
                debs.append(str(deb))
            if debs:
                install_exact_debs(debs, payload['tools'], packages(), [], environment)
        run(['apt-get', 'check'], env=environment)
        audit_packages(environment)
        record_git(payload, packages())
        pin_git(payload)
    finally:
        POLICY.unlink()
    if payload.get('php') and php_install(payload) not in ('applied', 'current'):
        raise RecipeFailure()
    # dnsmasq and Caddy are configured at enrollment, not while baking the disk.
    for service in ('ssh', 'docker', 'containerd'):
        if run(['systemctl', 'show', '-p', 'LoadState', '--value', service], required=False).stdout.strip() == 'loaded':
            run(['systemctl', 'enable', service])
    # `orbit` runs a task VM's nested topology with Incus. cloud-init adds groups only to a user it creates.
    if 'incus' in payload['tools']:
        run(['usermod', '--append', '--groups', 'incus-admin', 'orbit'])


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
        downloads = {name: download(tools[name], payload['architecture'], work / (name + '.archive')) for name in names if name != 'vp' and name in tools}
        install_javascript(tools, downloads, work, account, payload.get('vp_lock'))


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


def php_packages(payload):
    """The packages of the PHP build. php.install-build installs them, not the image package phase."""
    php = payload.get('php') or {}
    versions = php.get('input', {}).get('versions', {})
    return {pin['name'] for build in versions.values() for pin in build['packages']}


def php_install(payload):
    """Run php.install-build, the one installer of the recipe's PHP build, and return its outcome."""
    php = payload['php']
    result = subprocess.run(['python3', '-I', '-c', php['bootstrap'], 'php.install-build'], capture_output=True, text=True,
                            input=php['program'] + '\0' + json.dumps({**php['input'], 'git_read': {}}), timeout=900)
    command_output(result)
    try:
        return json.loads(result.stdout.strip().splitlines()[-1])['outcome']
    except (IndexError, KeyError, ValueError):
        raise RecipeFailure()


def image_verify(payload):
    marker = json.loads(Path('/etc/orbit-machine-recipe.json').read_text())
    if marker != {'identity': payload['identity'], 'profile': payload['profile']}:
        raise RecipeFailure()
    # Cleanup removed the build configuration, but authenticated snapshot lists remain. Never refresh here.
    with tempfile.TemporaryDirectory(prefix='orbit-image-verify-') as directory:
        work = Path(directory)
        image_apt_setup(payload, work)
        with image_apt_environment(payload, work, phase='verify'):
            selected = {name: pin for name, pin in payload['tools'].items()
                        if name not in ('rust', 'cargo', 'rust-std', 'clippy', 'rustfmt', 'orbit-agent', 'pi') and name not in php_packages(payload)}
            os.environ['SUDO_USER'] = 'orbit'
            observed = inspect(selected)
            assessment = assess(selected, observed, git_names=git_packages(payload))
            if any(tool['status'] not in ('current', 'newer_than_recipe') for tool in assessment.values()):
                raise RecipeFailure()
            if 'pnpm' in selected:
                result = run(['sudo', '-u', 'orbit', '-H', 'env', '-C', '/home/orbit',
                              '/usr/local/bin/pnpm', '--version'])
                if result.stdout.strip() != selected['pnpm']['version']:
                    raise RecipeFailure()
            if 'incus' in payload['tools'] and 'incus-admin' not in run(['id', '-nG', 'orbit']).stdout.split():
                raise RecipeFailure()
            for name, path in [('pi', '/home/orbit/.local/bin/pi-server'), ('orbit-agent', '/usr/local/bin/orbit-agent')]:
                if name in payload['tools']:
                    artifact = payload['tools'][name]['artifacts'][payload['architecture']]
                    algorithm = 'sha256' if 'sha256' in artifact else 'sha512'
                    with open(path, 'rb') as stream:
                        if hashlib.file_digest(stream, algorithm).hexdigest() != artifact[algorithm]:
                            raise RecipeFailure()
            # The installer finds nothing to change and checks the CLI's loaded extensions against the recipe.
            if payload.get('php') and php_install(payload) != 'current':
                raise RecipeFailure()
            rust_pins = {name: payload['tools'][name]['artifacts'][payload['architecture']]
                         for name in ('rust', 'cargo', 'rust-std', 'clippy', 'rustfmt') if name in payload['tools']}
            if rust_pins and json_file(Path('/opt/orbit/rust/.recipe-artifacts.json')) != rust_pins:
                raise RecipeFailure()
            for name, binary in [('rust', 'rustc'), ('cargo', 'cargo')]:
                if name in payload['tools'] and binary_version('/opt/orbit/rust/bin/' + binary) != payload['tools'][name]['version']:
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
        phases = {'apt-source': image_apt_source, 'packages': image_packages, 'javascript': image_javascript,
                  'rust': image_rust, 'agent': image_agent, 'verify': image_verify}
        if not re.fullmatch(r'[a-z-]+', profile) or phase not in phases:
            raise RecipeFailure('Invalid image phase')
        directory = Path('/var/log/orbit/recipe-images')
        directory.mkdir(parents=True, mode=0o700, exist_ok=True)
        directory.chmod(0o700)
        path = directory / (profile + '-' + phase + '.log')
        IMAGE_LOG = os.fdopen(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_APPEND, 0o600), 'w')
        os.chmod(path, 0o600)
        print('Recipe image phase: ' + profile + ':' + phase, file=IMAGE_LOG, flush=True)
        platform_check(payload['architecture'])
        phases[phase](payload)
        print('{"ok":true}')
        return 0
    except Exception as error:
        if isinstance(error, RecipeFailure) and error.detail:
            print(failure_output(error.detail), file=IMAGE_LOG if IMAGE_LOG is not None else sys.stderr, flush=True)
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
