# Existing Swamp load tooling

This directory imports existing operational tooling without changing its bytes. `import-sources.json` identifies each original path and SHA256. The installed Node209 snapshot came from `/usr/local/libexec/`; the publisher and transport helpers came from `/home/nckrtl/swamp-execution/platform/load-on-merge/` on Beast. The latter directory remains the running publisher location. The installed entry point is `/usr/local/libexec/orbit-swamp-main-load.py` on Node209.

This import does not install code, change services, apply grants or admit business runs. The existing operator supplies the Gateway-managed SSH route, publisher custody, feed and runtime files. These paths and assumptions describe that existing installation; this directory is not a new fleet installer. The small manifest-grant load step is PR #2230 and merges first.

The imported code retains its existing behavior and limitations. In particular, its registry audit still needs the existing explicit Lab #4223 acceptance continuation for deleted-source entries; no policy fix is hidden in this import. Existing restart, health and drain loops are deployment tooling, not business workflow orchestration.
