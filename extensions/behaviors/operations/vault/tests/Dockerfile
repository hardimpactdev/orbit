# Dev-only worker: the pinned native runtime plus the production Python dependency.
FROM orbit-swamp-worker:20261028.205235.0-sha.1602a617
USER root
RUN apt-get update && apt-get install -y --no-install-recommends python3 libssl3t64 \
    && rm -rf /var/lib/apt/lists/*
USER swamp
