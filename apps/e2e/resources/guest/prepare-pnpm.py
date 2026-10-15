# Appended to the recipe's fixed installer helpers; only the selected pnpm pin is supplied.
pin = json.loads(sys.argv[1])
account = pwd.getpwnam('orbit')
scope = vite_scope(account)
result = run(['sudo', '-u', 'orbit', '-H', 'env', '-C', account.pw_dir,
              'VP_HOME=' + str(scope), str(scope / 'bin/pnpm'), '--version'], required=False)
if result.returncode != 0 or result.stdout.strip() != pin['version']:
    architecture = run(['dpkg', '--print-architecture']).stdout.strip()
    with tempfile.TemporaryDirectory(prefix='orbit-topology-pnpm-') as directory:
        work = Path(directory)
        archive = download(pin, architecture, work / 'pnpm.archive')
        install_javascript({'pnpm': pin}, {'pnpm': archive}, work, account)
    result = run(['sudo', '-u', 'orbit', '-H', 'env', '-C', account.pw_dir,
                  'VP_HOME=' + str(scope), str(scope / 'bin/pnpm'), '--version'])
    if result.stdout.strip() != pin['version']:
        raise RecipeFailure()
