"""Enable the canonical Caddy service before its authoritative publication."""

import json

from phase_common import control

if __name__ == "__main__":
    control("enable", "--now", "caddy.service")
    print(json.dumps({"ready": True}))
