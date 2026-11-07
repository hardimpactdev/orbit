<?php

declare(strict_types=1);

namespace App\Domain\NodeFacts;

use App\Domain\Hibernation\RuntimeHibernation;

/**
 * The kinds of fact a Node agent reports, each with the validation rules of its `data`. The agent only observes; the
 * Gateway answers reads such as Doctor from the stored facts.
 */
enum NodeFactKind: string
{
    /** Operating system, architecture and filesystem space. */
    case Host = 'host';

    /** Systemd units and Docker containers the watch list names or the agent owns, with their state. */
    case Units = 'units';

    /** One Git checkout under the Node's apps root, named by its path in `subject`. */
    case Checkout = 'checkout';

    /** The paths of every checkout the agent found; a checkout row whose path it no longer names is deleted. */
    case Checkouts = 'checkouts';

    /** The packages one Tool Manager holds, named by the manager in `subject`, read from its own state files. */
    case Packages = 'packages';

    /** Whether UFW is installed and enabled, and its rules, read from its configuration and rules files. */
    case Ufw = 'ufw';

    /**
     * The key names of one Instance `.env`, named by its path in `subject`; never a value. `keys` is null when the agent
     * may not read the file: Orbit writes a managed `.env` as 0640 with group `orbit-env`, which the agent's unit holds.
     */
    case EnvKeys = 'env_keys';

    /** The Node's local accounts with their primary group, home and shell, read from `/etc/passwd` and `/etc/group`. */
    case Accounts = 'accounts';

    /**
     * The outside hosts one task VM connected to, named by the VM in `subject`, read by the host's agent from the bridge logs
     * (docs/reference/task-vm-egress-log.md). The Gateway adds each row to the task group that held the VM.
     */
    case Egress = 'egress';

    /** The name of a task VM host's bridge: `orbittask` and 1 to 6 lower case letters or digits. */
    public const string EgressNetworkPattern = '/\\Aorbittask[a-z0-9]{1,6}\\z/';

    /** Free space is stored in whole steps of this size, so ordinary disk use is not a change to write. */
    public const int FreeStepBytes = 268_435_456;

    /** @return array<string, list<string>|string> */
    public function rules(): array
    {
        return match ($this) {
            self::Host => [
                'os' => ['required', 'string', 'max:64'],
                'arch' => ['required', 'string', 'max:32'],
                'filesystems' => ['present', 'array', 'list', 'max:64'],
                'filesystems.*.mount' => ['required', 'string', 'starts_with:/', 'max:4096'],
                'filesystems.*.size' => ['required', 'integer', 'min:0'],
                'filesystems.*.free' => ['required', 'integer', 'min:0'],
            ],
            self::Units => [
                'docker' => ['required', 'string', 'in:available,absent'],
                'units' => ['present', 'array', 'list', 'max:4096'],
                'units.*.name' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9@._:-]+\z/'],
                'units.*.runtime' => ['required', 'string', 'in:systemd,docker'],
                'units.*.state' => ['required', 'string', 'max:32', 'regex:/\A[a-z-]+\z/'],
                // Agents before 0.10.0 report neither a systemd unit's load and sub state nor the awake markers.
                'units.*.load' => ['sometimes', 'string', 'max:32', 'regex:/\A[a-z-]+\z/'],
                'units.*.sub' => ['sometimes', 'string', 'max:32', 'regex:/\A[a-z-]+\z/'],
                'awake' => ['sometimes', 'array', 'list', 'max:4096'],
                'awake.*' => ['required', 'string', 'starts_with:'.RuntimeHibernation::MarkerDirectory.'/', 'max:4096'],
            ],
            self::Checkout => [
                'owner' => ['present', 'nullable', 'string', 'max:64'],
                'top_level' => ['present', 'nullable', 'string', 'starts_with:/', 'max:4096'],
                'common_dir' => ['present', 'nullable', 'string', 'starts_with:/', 'max:4096'],
                'linked' => ['required', 'boolean'],
                'worktrees' => ['present', 'array', 'list', 'max:256'],
                'worktrees.*' => ['required', 'string', 'starts_with:/', 'max:4096'],
                'origin' => ['present', 'nullable', 'string', 'max:2048'],
                'branch' => ['present', 'nullable', 'string', 'max:255'],
                'head' => ['present', 'nullable', 'string', 'regex:/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/'],
                'default_branch' => ['present', 'nullable', 'string', 'max:255'],
                'application' => ['required', 'string', 'starts_with:/', 'max:4096'],
                'artisan' => ['required', 'string', 'in:regular,symlink,absent'],
                'composer' => ['present', 'nullable', 'string', 'max:1048576'],
                // Agents before 0.8.0 do not report `env`.
                'env' => ['sometimes', 'nullable', 'array'],
                'env.kind' => ['required_with:env', 'string', 'in:regular,symlink,other'],
                'env.mode' => ['required_with:env', 'string', 'regex:/\A0[0-7]{3}\z/'],
                'env.owner' => ['present_with:env', 'nullable', 'string', 'max:64'],
            ],
            self::Checkouts => [
                'paths' => ['present', 'array', 'list', 'max:4096'],
                'paths.*' => ['required', 'string', 'starts_with:/', 'max:1024'],
            ],
            self::Packages => [
                'scope' => ['present', 'nullable', 'string', 'starts_with:/', 'max:4096'],
                'packages' => ['sometimes', 'array', 'list', 'max:20000'],
                'packages.*.name' => ['required', 'string', 'max:255'],
                'packages.*.version' => ['required', 'string', 'max:255'],
                'roots' => ['sometimes', 'array', 'list', 'max:1000'],
                'roots.*' => ['required', 'string', 'max:255'],
                'sources' => ['sometimes', 'array', 'list', 'max:256'],
                'keys' => ['sometimes', 'array', 'list', 'max:256'],
                'sources.*.path' => ['required', 'string', 'starts_with:/', 'max:4096'],
                'keys.*.path' => ['required', 'string', 'starts_with:/', 'max:4096'],
                'sources.*.sha256' => ['required', 'string', 'regex:/\\A[a-f0-9]{64}\\z/'],
                'keys.*.sha256' => ['required', 'string', 'regex:/\\A[a-f0-9]{64}\\z/'],
                // An empty file, such as Ubuntu's removed-keys keyring, arrives as null.
                'sources.*.content' => ['present', 'nullable', 'string', 'max:87384'],
                'keys.*.content' => ['present', 'nullable', 'string', 'max:87384'],
                'formulae' => ['sometimes', 'array', 'list', 'max:5000'],
                'formulae.*.name' => ['required', 'string', 'max:255'],
                'formulae.*.versions' => ['present', 'array', 'list', 'max:64'],
                'formulae.*.tap' => ['present', 'nullable', 'string', 'max:255'],
                'formulae.*.on_request' => ['present', 'nullable', 'boolean'],
                'formulae.*.dependencies' => ['present', 'array', 'list', 'max:1000'],
                'casks' => ['sometimes', 'array', 'list', 'max:5000'],
                'casks.*.name' => ['required', 'string', 'max:255'],
                'casks.*.versions' => ['present', 'array', 'list', 'max:64'],
            ],
            self::EnvKeys => [
                'kind' => ['required', 'string', 'in:absent,regular,symlink,other'],
                'keys' => ['present', 'nullable', 'array', 'list', 'max:2000'],
                'keys.*' => ['required', 'string', 'max:255', 'regex:/\\A[A-Za-z_][A-Za-z0-9_.]*\\z/'],
                'testing_tracked' => ['required', 'boolean'],
            ],
            self::Accounts => [
                'accounts' => ['present', 'array', 'list', 'max:512'],
                'accounts.*.user' => ['required', 'string', 'regex:/\A[a-z_][a-z0-9_-]{0,31}\$?\z/'],
                'accounts.*.uid' => ['required', 'integer', 'min:0'],
                'accounts.*.group' => ['required', 'string', 'regex:/\A[a-z_][a-z0-9_-]{0,31}\$?\z/'],
                'accounts.*.home' => ['required', 'string', 'starts_with:/', 'max:4096'],
                'accounts.*.shell' => ['required', 'string', 'starts_with:/', 'max:4096'],
            ],
            self::Egress => [
                'network' => ['required', 'string', 'regex:'.self::EgressNetworkPattern],
                'mac' => ['required', 'string', 'regex:/\A(?:[0-9a-f]{2}:){5}[0-9a-f]{2}\z/'],
                'rows' => ['present', 'array', 'list', 'max:1500'],
                'rows.*.destination' => ['required', 'string', 'max:253', 'regex:/\A[a-z0-9._-]+\z/'],
                'rows.*.kind' => ['required', 'string', 'in:name,address'],
                // A name that nothing connected to has no port and no protocol.
                'rows.*.port' => ['present', 'nullable', 'integer:strict', 'between:1,65535', 'required_with:rows.*.protocol'],
                'rows.*.protocol' => ['present', 'nullable', 'string', 'in:tcp,udp', 'required_with:rows.*.port'],
                'rows.*.addresses' => ['present', 'array', 'list', 'max:8'],
                'rows.*.addresses.*' => ['required', 'ipv4'],
                'rows.*.sources' => ['present', 'array', 'list', 'min:1', 'max:2'],
                'rows.*.sources.*' => ['required', 'string', 'in:conn,dns'],
                'rows.*.flags' => ['present', 'array', 'list', 'max:3'],
                'rows.*.flags.*' => ['required', 'string', 'in:dot,dns,doh'],
                'rows.*.first_seen' => ['required', 'date_format:Y-m-d\\TH:i:s\\Z'],
                'overflow' => ['required', 'integer', 'min:0'],
            ],
            self::Ufw => [
                'installed' => ['required', 'boolean'],
                'enabled' => ['required', 'boolean'],
                'rules' => ['present', 'array', 'list', 'max:1024'],
                // A rule the agent could not read arrives as {unreadable: true}; Doctor then reports the check unverifiable.
                'rules.*' => ['array'],
                'rules.*.unreadable' => ['sometimes', 'accepted'],
                'rules.*.action' => ['sometimes', 'string', 'max:32'],
                'rules.*.protocol' => ['sometimes', 'string', 'max:32'],
                'rules.*.port' => ['sometimes', 'string', 'max:255'],
                'rules.*.destination' => ['sometimes', 'string', 'max:64'],
                'rules.*.source_port' => ['sometimes', 'string', 'max:255'],
                'rules.*.source' => ['sometimes', 'string', 'max:64'],
                'rules.*.direction' => ['sometimes', 'string', 'in:in,out,fwd'],
                'rules.*.in_interface' => ['nullable', 'string', 'max:64'],
                'rules.*.out_interface' => ['nullable', 'string', 'max:64'],
                'rules.*.family' => ['sometimes', 'string', 'in:v4,v6'],
                // A rule without a comment arrives as "", which the request turns into null.
                'rules.*.comment' => ['sometimes', 'nullable', 'string', 'max:1024'],
                'rules.*.app' => ['sometimes', 'boolean'],
            ],
        };
    }

    /** Whether a fact of this kind names its item in `subject`. */
    public function hasSubject(): bool
    {
        return $this->subjectRules() !== ['prohibited'];
    }

    /**
     * The rules of `subject`: a checkout's or `.env` path, a Tool Manager's name, or none.
     *
     * @return list<string>
     */
    public function subjectRules(): array
    {
        return match ($this) {
            self::Checkout => ['required', 'string', 'starts_with:/', 'max:1024'],
            self::Packages => ['required', 'string', 'regex:/\\A[a-z][a-z0-9-]{0,31}\\z/'],
            self::EnvKeys => ['required', 'string', 'starts_with:/', 'ends_with:/.env', 'max:1024'],
            self::Egress => ['required', 'string', 'regex:/\\A[a-z0-9][a-z0-9._-]{0,62}\\z/'],
            default => ['prohibited'],
        };
    }

    /**
     * The data as the Gateway stores it. A `.env` fact keeps only its named fields. A host's free space moves with every log line, so it is rounded down to
     * {@see self::FreeStepBytes}; the digest of the rounded data then changes only when the space really changes.
     *
     * @param  array<string, mixed>  $data  validated by {@see self::rules()}
     * @return array<string, mixed>
     */
    public function stored(array $data): array
    {
        // A `.env` fact keeps its named fields only, so no other field of that file can ever be stored.
        if ($this === self::EnvKeys) {
            return array_intersect_key($data, array_flip(['kind', 'keys', 'testing_tracked']));
        }
        if ($this !== self::Host || ! is_array($data['filesystems'] ?? null)) {
            return $data;
        }
        $data['filesystems'] = array_map(
            static fn (mixed $filesystem): mixed => is_array($filesystem) && is_int($filesystem['free'] ?? null)
                ? array_replace($filesystem, ['free' => intdiv($filesystem['free'], self::FreeStepBytes) * self::FreeStepBytes])
                : $filesystem,
            $data['filesystems'],
        );

        return $data;
    }
}
