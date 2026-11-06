<?php

declare(strict_types=1);

use App\Commands\Proof\ProofCommand;
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
use Orbit\Sdk\Requests\Proof\VerifyProofRequest;
use Saloon\Http\Faking\MockClient;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__.'/../../Support/ProofVerifyMocks.php';

beforeEach(function (): void {
    MockClient::destroyGlobal();
    Sleep::fake(syncWithCarbon: true);
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-proof-verify-prompt-'.Str::uuid();
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
function proof_verify_prompt(array $keys): array
{
    $command = new ProofPromptsCommand(new ProofPromptsTerminal($keys));
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    return PromptContext::preserve(function () use ($tester): array {
        Prompt::fallbackWhen(false);

        return [$tester->execute([]), $tester->getDisplay()];
    });
}

describe('proof in a terminal', function (): void {
    it('asks for an omitted command again until it is valid, then starts one proof', function (): void {
        $mock = proof_verify_gateway([proof_verify_operation('passed')]);

        [$status, $display] = proof_verify_prompt(['doctor', Key::ENTER, str_repeat(Key::BACKSPACE, 6), 'Firewall:allow', Key::ENTER, str_repeat(Key::BACKSPACE, 14), 'firewall:allow', Key::ENTER]);

        expect($status)->toBe(0)
            ->and($display)->toContain('Command', 'Enter the command as family:action, for example firewall:allow.', 'Proof passed.');
        $mock->assertSentCount(1, VerifyProofRequest::class);
        $mock->assertSent(static fn ($request): bool => $request instanceof VerifyProofRequest && $request->body()->all() === ['command' => 'firewall:allow']);
    });

    it('sends no request when the prompt is cancelled with Ctrl-C', function (): void {
        $mock = proof_verify_gateway([proof_verify_operation('passed')]);

        [$status, $display] = proof_verify_prompt([Key::CTRL_C]);

        expect($status)->toBe(1)->and($display)->toContain('No command was selected.');
        $mock->assertNothingSent();
    });
});

final class ProofPromptsCommand extends ProofCommand
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

final class ProofPromptsTerminal extends Terminal
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
