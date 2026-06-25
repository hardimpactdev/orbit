<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyKind;

pest()->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway_app-dev_app-prod_ingress');

it('shows release metadata from a configured release candidate manifest', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdevAppprodIngress);
    $passed = false;

    try {
        $checkouts = e2eCheckout($topology, roles: ['ingress']);
        $result = $topology->ssh(
            'ingress',
            sprintf(
                <<<'BASH'
                    cd %s
                    workdir=$(mktemp -d)
                    port=$((20000 + RANDOM %% 20000))
                    cleanup() {
                        test -z "${server_pid:-}" || kill "${server_pid}" >/dev/null 2>&1 || true
                        rm -rf "${workdir}"
                    }
                    trap cleanup EXIT

                    cat > "${workdir}/orbit-release-manifest.json" <<'JSON'
                    {"schema_version":1,"version":"9.9.998","source":"topology-candidate","build_id":"2026-06-25T143000Z-e2e"}
                    JSON

                    cat > "${workdir}/install.json" <<'JSON'
                    {"schema_version":1,"version":"9.9.998","installed_at":"2026-06-25T14:31:00+00:00"}
                    JSON

                    php -S "127.0.0.1:${port}" -t "${workdir}" >/tmp/orbit-version-manifest-server.log 2>&1 &
                    server_pid=$!

                    for attempt in 1 2 3 4 5 6 7 8 9 10; do
                        if php -r 'exit(@file_get_contents($argv[1]) === false ? 1 : 0);' "http://127.0.0.1:${port}/orbit-release-manifest.json"; then
                            break
                        fi

                        sleep 0.2
                    done

                    ORBIT_VERSION=9.9.998 \
                    ORBIT_RELEASE_MANIFEST_URL="http://127.0.0.1:${port}/orbit-release-manifest.json" \
                    ORBIT_INSTALL_METADATA_PATH="${workdir}/install.json" \
                    ORBIT_DISPLAY_TIMEZONE=Europe/Amsterdam \
                        ./apps/cli/orbit --version --no-ansi
                    BASH,
                escapeshellarg($checkouts['ingress']),
            ),
            timeoutSeconds: 180,
        );

        expect($result->successful())
            ->toBeTrue($result->output().$result->errorOutput())
            ->and($result->output())
            ->toContain('Version       9.9.998')
            ->toContain('Released at   25-06-2026 - 16:30')
            ->toContain('Installed at  25-06-2026 - 16:31')
            ->not->toContain('Released at   unknown');

        $passed = true;
    } finally {
        e2eTopologyCleanup($passed, $topology);
    }
});
