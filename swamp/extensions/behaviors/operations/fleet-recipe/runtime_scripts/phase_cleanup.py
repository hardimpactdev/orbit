"""Bound releases, preserving the prior and any candidate that reached cutover."""

import os

from phase_common import cleanup, context, ready

data, account = context()
cleanup(data, account, os.environ["ORBIT_ROLE_RETAIN_CANDIDATE"] == "true")
ready()
