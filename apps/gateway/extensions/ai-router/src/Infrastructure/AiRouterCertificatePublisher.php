<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Infrastructure\Caddy\CaddyPublicationLock;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use SensitiveParameter;

/** Publishes and removes the Orbit CA leaf certificate on the ai-router Process Node. */
final readonly class AiRouterCertificatePublisher
{
    public function command(
        string $certificatePem,
        #[SensitiveParameter] string $privateKeyPem,
    ): RemoteCommand {
        $certificateEncoded = base64_encode($certificatePem);
        $keyEncoded = base64_encode($privateKeyPem);
        $current = AiRouterFootprint::CertificateCurrentDirectory;
        $caddyService = AiRouterFootprint::CaddyServiceName;
        $lockScript = CaddyPublicationLock::script(CaddyPublicationLock::Path);

        $script = <<<BASH
            current={$current}
            caddy_service={$caddyService}
            {$lockScript}
            candidate="\$current.orbit-candidate"
            trap 'rm -rf -- "\$candidate"' EXIT
            install -d -o root -g caddy -m 0750 -- "\$candidate"
            printf '%s' '{$certificateEncoded}' | base64 --decode > "\$candidate/ai_router.pem"
            printf '%s' '{$keyEncoded}' | base64 --decode > "\$candidate/ai_router.key"
            chown root:caddy "\$candidate/ai_router.pem" "\$candidate/ai_router.key"
            chmod 0640 "\$candidate/ai_router.pem" "\$candidate/ai_router.key"
            certificate_public=\$(openssl x509 -in "\$candidate/ai_router.pem" -pubkey -noout)
            private_public=\$(openssl pkey -in "\$candidate/ai_router.key" -pubout)
            test "\$certificate_public" = "\$private_public"
            if [ -d "\$current" ] \\
                && cmp -s -- "\$candidate/ai_router.pem" "\$current/ai_router.pem" \\
                && cmp -s -- "\$candidate/ai_router.key" "\$current/ai_router.key"; then
                rm -rf -- "\$candidate"
                exit 0
            fi
            rm -rf -- "\$current"
            mv -fT -- "\$candidate" "\$current"
            if systemctl is-active --quiet "\$caddy_service"; then
                systemctl reload-or-restart "\$caddy_service"
            fi
            BASH;

        return new RemoteCommand(
            arguments: ['sudo', 'bash', '-seu'],
            protectedInput: ProtectedInput::fromString($script),
        );
    }

    public function removeCommand(): RemoteCommand
    {
        return new RemoteCommand(
            arguments: ['sudo', 'rm', '-rf', '--', AiRouterFootprint::CertificateCurrentDirectory],
        );
    }
}
