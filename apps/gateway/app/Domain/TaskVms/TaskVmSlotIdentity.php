<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * The public text of a slot's identity tree: the WireGuard config, the observer configuration and CA, the Pi unit and
 * `models.json`. The identity phase adds the files that hold a key or a secret (the WireGuard and SSH host
 * keys, the observer secret, the Pi token and the model key in `models.json`) on the host, so none of them passes
 * through the Gateway or Swamp. The tree and its `REVISION` are defined in
 * docs/reference/compute-drivers.md (What a slot holds).
 */
final readonly class TaskVmSlotIdentity
{
    /** Where the identity phase puts the model key in the `models.json` text. */
    public const string ModelKeyMark = '@MODEL_KEY@';

    /**
     * @param  array{config:string,dns_marker:string}  $peerFiles  from NodeNetworkConvergeInput::peerFiles
     * @param  list<string>  $models
     * @param  array{configuration:string,certificate:string}  $agent  from NodeAgentSshExecutor::configurationForRoot
     * @param  array{name:string,content:string}  $piUnit  the slot's `pi-server` Process unit, from TaskVmEnrollmentIntent::unit
     * @return array<string,string> tree path => text; the key marks stay in place
     */
    public static function files(array $peerFiles, string $origin, array $models, array $agent, array $piUnit): array
    {
        return [
            'etc/wireguard/orbit.conf' => $peerFiles['config'],
            'etc/wireguard/orbit.dns-link' => $peerFiles['dns_marker'],
            // The observer reads both; they are the same for every slot, so a change needs a new revision, not a new image.
            'etc/orbit/agent/config.toml' => $agent['configuration'],
            'etc/orbit/agent/ca.pem' => $agent['certificate'],
            // Pi runs under the unit name the Gateway records, because `journalctl --unit` does not follow an alias. The unit
            // and its ordering come with the tree, as the image cannot know the Process id. The identity unit installs them,
            // reloads systemd and starts the baked orbit-slot.target again, which is then bound to the Pi unit.
            "etc/systemd/system/{$piUnit['name']}" => $piUnit['content'],
            "etc/systemd/system/{$piUnit['name']}.d/10-slot.conf" => "[Unit]\nAfter=wg-quick@orbit.service\n",
            'etc/systemd/system/orbit-slot.target.d/10-pi.conf' => "[Unit]\nBindsTo={$piUnit['name']}\nAfter={$piUnit['name']}\n",
            // Without it cloud-init deletes the slot's SSH host keys on the first boot of every clone.
            'etc/cloud/cloud.cfg.d/99-orbit-slot.cfg' => "ssh_deletekeys: false\n",
            'home/orbit/.pi/agent/models.json' => json_encode(['providers' => ['orbit-task-vm' => [
                'baseUrl' => rtrim($origin, '/').'/v1', 'api' => 'openai-responses', 'apiKey' => self::ModelKeyMark,
                'models' => array_map(static fn (string $id): array => ['id' => $id, 'reasoning' => true], $models)]]],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        ];
    }

    /**
     * The hash of everything the Gateway renders into the tree. The volume holds the same value in `REVISION`, so a
     * claim finds a stale volume with one comparison. Secrets are not part of it: they never rotate.
     *
     * @param  array<string,string>  $files
     */
    public static function revision(string $name, array $files): string
    {
        ksort($files);

        return hash('sha256', json_encode(['name' => $name, 'files' => $files], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
