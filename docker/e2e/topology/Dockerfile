FROM debian:bookworm-slim

LABEL org.orbit.e2e.substrate="docker-first"

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        curl \
        docker.io \
        git \
        openssh-client \
        openssh-server \
        sudo \
    && mkdir -p /run/sshd \
    && rm -rf /var/lib/apt/lists/*

RUN useradd -m -s /bin/bash control \
    && useradd -m -s /bin/bash orbit \
    && usermod -aG docker control \
    && usermod -aG docker orbit \
    && echo 'control ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/control \
    && echo 'orbit ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/orbit \
    && chmod 440 /etc/sudoers.d/control /etc/sudoers.d/orbit

RUN printf '%s\n' \
        '#!/usr/bin/env bash' \
        'set -euo pipefail' \
        'runtime_container="${ORBIT_RUNTIME_CONTAINER:-orbit-runtime}"' \
        'if [ -n "${ORBIT_E2E_DOCKER_NETWORK:-}" ]; then' \
        '    sudo docker network connect "${ORBIT_E2E_DOCKER_NETWORK}" "${runtime_container}" >/dev/null 2>&1 || true' \
        'fi' \
        'exec sudo docker exec \' \
        '    --env "ORBIT_HOST_CWD=$PWD" \' \
        '    --env "ORBIT_SOURCE_PATH=$PWD" \' \
        '    --env "ORBIT_RUNTIME_CONTAINER=$runtime_container" \' \
        '    "${runtime_container}" \' \
        '    orbit "$@"' \
        > /usr/local/bin/orbit \
    && chmod 755 /usr/local/bin/orbit

RUN printf '%s\n' \
        '#!/usr/bin/env bash' \
        'set -euo pipefail' \
        'mkdir -p /run/sshd /home/orbit/.config/orbit/logs' \
        'chown -R orbit:orbit /home/orbit/.config' \
        'exec /usr/sbin/sshd -D -e' \
        > /usr/local/bin/orbit-e2e-container \
    && chmod 755 /usr/local/bin/orbit-e2e-container

WORKDIR /opt/orbit-source
COPY . /opt/orbit-source

RUN install -d -o control -g control /home/control/orbit \
    && install -d -o orbit -g orbit /home/orbit/orbit \
    && cp -a /opt/orbit-source/. /home/control/orbit/ \
    && cp -a /opt/orbit-source/. /home/orbit/orbit/ \
    && rm -rf /opt/orbit-source/storage/app/orbit/ca /opt/orbit-source/storage/app/orbit/certs /opt/orbit-source/storage/app/orbit/keys \
    && rm -rf /home/control/orbit/storage/app/orbit/ca /home/control/orbit/storage/app/orbit/certs /home/control/orbit/storage/app/orbit/keys \
    && rm -rf /home/orbit/orbit/storage/app/orbit/ca /home/orbit/orbit/storage/app/orbit/certs /home/orbit/orbit/storage/app/orbit/keys \
    && chown -R control:control /home/control/orbit \
    && chown -R orbit:orbit /home/orbit/orbit

CMD ["/usr/local/bin/orbit-e2e-container"]
