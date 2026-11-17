<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\MachineSoftware\InstanceFolders;

/**
 * The server that runs in a container with a server (docs/reference/development-containers.md#container-server): the T3 server or the
 * Conn Server. A Project chooses it; the runtime records the choice at its reservation, and every later step reads that
 * record. The unit and credentials stay; Conn also receives its shared preview browser path.
 */
enum ContainerServer: string
{
    case T3 = 't3';
    case Conn = 'conn';

    /** The program's name in the agent folder's `bin`, as a recipe part's `bin` names it. */
    public function bin(): string
    {
        return match ($this) {
            self::T3 => 't3',
            self::Conn => 'conn-server',
        };
    }

    /** The program in a container: every call inside it and the unit's `ExecStart` run it. */
    public function program(): string
    {
        return InstanceHostIntent::AgentFolder.'/current/agent/bin/'.$this->bin();
    }

    /** The unit's command: the program with the image's arguments. */
    public function serve(): string
    {
        return $this->program().' serve --host=0.0.0.0 --port='.InstanceHostIntent::T3Port.' --no-browser';
    }

    /** The server command and, for Conn, the agent folder's one headless Chrome, which agent-browser opens too. */
    public function dropIn(InstanceFolders $folders): string
    {
        $browser = '';
        if ($this === self::Conn) {
            $browser = 'Environment=CONN_SERVER_BROWSER_PATH='.InstanceContainer::headlessChrome($folders)."\nEnvironment=T3CODE_SERVER_BROWSER_SANDBOX=0\n";
        }

        return "# Managed by Orbit.\n[Service]\nExecStart=\nExecStart=".$this->serve()."\n".$browser;
    }

    /** Refuses a server whose program the recipe's agent folder does not pin: no container could start it. */
    public function assertPinned(InstanceFolders $folders): void
    {
        foreach ($folders->agent as $part) {
            if (array_key_exists($this->bin(), $part->bin)) {
                return;
            }
        }

        throw new ResourceOperationException('project.container_server_unavailable',
            "The agent folder has no {$this->bin()} program: the recipe pins no release of it.", 409);
    }
}
