<?php

declare(strict_types=1);

use App\Commands\Proof\RunProofCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Support\Console\CommandPrompts;
use App\Support\Console\ConsoleMode;
use App\Support\Console\PromptAborted;
use App\Support\Console\PromptContext;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;
use Orbit\Sdk\Requests\Proof\RunProofRequest;
use Saloon\Http\Faking\MockClient;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__.'/../../Support/ProofRunMocks.php';

beforeEach(function (): void {
    MockClient::destroyGlobal();
    Sleep::fake(syncWithCarbon: true);
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-proof-prompt-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    Sleep::fake(false);
    new Filesystem()->deleteDirectory($this->orbitHome);
});

/**
 * @param  list<string>  $keys
 * @return array{int, string}
 */
function proof_run_prompt(array $keys): array
{
    $command = new RunProofPromptsCommand(new RunProofPromptsTerminal($keys));
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    return PromptContext::preserve(function () use ($tester): array {
        Prompt::fallbackWhen(false);

        return [$tester->execute([]), $tester->getDisplay()];
    });
}

describe('proof:run in a terminal', function (): void {
    it('asks for an omitted commit again until it is valid, then starts one run', function (): void {
        $mock = proof_run_gateway([proof_run_operation('passed')]);

        [$status, $display] = proof_run_prompt(['0123456', Key::ENTER, str_repeat(Key::BACKSPACE, 7), strtoupper(PROOF_RUN_SHA), Key::ENTER, str_repeat(Key::BACKSPACE, 40), PROOF_RUN_SHA, Key::ENTER]);

        expect($status)->toBe(0)
            ->and($display)->toContain('Commit', 'Enter the full 40 or 64 character lowercase SHA.', 'Proof passed.');
        $mock->assertSentCount(1, RunProofRequest::class);
        $mock->assertSent(static fn ($request): bool => $request instanceof RunProofRequest && $request->body()->all() === ['commit' => PROOF_RUN_SHA]);
    });

    it('sends no request when the prompt is cancelled with Ctrl-C', function (): void {
        $mock = proof_run_gateway([proof_run_operation('passed')]);

        [$status, $display] = proof_run_prompt([Key::CTRL_C]);

        expect($status)->toBe(1)->and($display)->toContain('No commit was selected.');
        $mock->assertNothingSent();
    });
});

final class RunProofPromptsCommand extends RunProofCommand
{
    public function __construct(private readonly Terminal $terminal)
    {
        parent::__construct();
    }

    protected function consoleMode(?OutputInterface $output = null): ConsoleMode
    {
        return new ConsoleMode(false, true, false, false, 100);
    }

    protected function commandPrompts(): CommandPrompts
    {
        return new CommandPrompts($this->consoleMode(), $this->output, $this->terminal);
    }
}

final class RunProofPromptsTerminal extends Terminal
{
    /** @param list<string> $keys */
    public function __construct(private array $keys)
    {
        parent::__construct();
    }

    public function read(): string
    {
        return array_shift($this->keys) ?? throw new PromptAborted('Input ended.');
    }

    public function setTty(string $mode): void {}

    public function restoreTty(): void {}

    public function cols(): int
    {
        return 100;
    }

    public function lines(): int
    {
        return 40;
    }
}
