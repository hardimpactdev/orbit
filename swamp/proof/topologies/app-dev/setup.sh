#!/usr/bin/env bash
set -euo pipefail
# The app-dev role recipe comes from the canonical app-dev recipe image.
# No second PHP, Caddy or runtime installer is maintained in the proof library.
command -v php
command -v composer
command -v git
command -v caddy
