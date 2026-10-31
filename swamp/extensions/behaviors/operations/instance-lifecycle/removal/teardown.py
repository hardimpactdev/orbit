"""One placed Instance removal action: teardown."""

import hashlib
import json
import os
import subprocess
import sys
import tempfile

from identity import Refusal, account, directory, environment_file, require

try:
    program = json.loads(os.environ['ORBIT_TEARDOWN_INPUT'])
    script = os.environ.pop('ORBIT_TEARDOWN_COMMAND')
    require(hashlib.sha256(script.encode()).hexdigest() == program['step']['sha256'])
    production = os.environ['ORBIT_ENVIRONMENT'] == 'production'
    owner = account(program, production)
    require(owner is not None)
    directory(program['checkout'], owner.pw_uid, owner.pw_gid)
    environment = environment_file(program['environment_file'], owner, production)
    environment.update(PATH='/usr/local/bin:/usr/bin:/bin', HOME=owner.pw_dir,
                       USER=owner.pw_name, LOGNAME=owner.pw_name)
    # The private script is a protected file, never sh -c argv.
    with tempfile.TemporaryDirectory(prefix='orbit-teardown-') as temporary:
        os.chmod(temporary, 0o700)
        path = temporary + '/step.sh'
        descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(descriptor, 'w') as output:
            output.write(script)
        if owner.pw_uid != os.geteuid():
            os.chown(temporary, owner.pw_uid, owner.pw_gid)
            os.chown(path, owner.pw_uid, owner.pw_gid)
            command = ['/usr/sbin/runuser', '-u', program['user'], '--', '/bin/sh', path]
        else:
            command = ['/bin/sh', path]
        result = subprocess.run(command, cwd=program['checkout'], stdin=subprocess.DEVNULL,
                                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                env=environment,
                                timeout=program['step']['timeout_seconds'], check=False)
        require(result.returncode == 0, 'instance.teardown_failed')
    print(json.dumps({'instance_id': program['instance_id'], 'step_name': program['step']['name'], 'outcome': 'succeeded'}))
except (Refusal, OSError, KeyError, ValueError, subprocess.SubprocessError) as error:
    print(json.dumps({'error_code': getattr(error, 'code', 'instance.teardown_failed')}))
    sys.exit(1)
