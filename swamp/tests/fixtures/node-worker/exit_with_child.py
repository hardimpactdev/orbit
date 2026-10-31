"""A worker-like main process exits once while its connection child stays alive."""

import os
import sys
import time
from pathlib import Path

phase = Path(sys.argv[1])
ready = Path(sys.argv[2])

if not phase.exists():
    phase.write_text('first start')
    if os.fork() == 0:
        time.sleep(10)
        os._exit(0)
    os._exit(1)

ready.write_text('restarted')
time.sleep(10)
