<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Doctor\RunDoctorRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=280');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-doctor-contract-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('doctor discovery contract', function (): void {
    it('exits 0 for a recorded informational report', function (string $fixture): void {
        run_doctor_operation_contract($fixture, 'doctor', ['--family' => ['tool']], $fixture.'.human.txt', 0);
        run_doctor_operation_contract($fixture, 'doctor', ['--family' => ['tool'], '--json' => true], $fixture.'.json', 0);
    })->with([
        'informational only' => ['doctor/doctor/informational'],
        'empty managers' => ['doctor/doctor/empty-managers'],
    ]);

    it('exits 1 for a recorded unhealthy report', function (string $fixture): void {
        run_doctor_operation_contract($fixture, 'doctor', ['--family' => ['tool']], $fixture.'.human.txt', 1);
        run_doctor_operation_contract($fixture, 'doctor', ['--family' => ['tool'], '--json' => true], $fixture.'.json', 1);
    })->with([
        'mixed drift' => ['doctor/doctor/mixed'],
        'unreachable' => ['doctor/doctor/unreachable'],
        'truncated inventory' => ['doctor/doctor/truncated'],
    ]);
});

/** @param array<string, mixed> $arguments */
function run_doctor_operation_contract(string $fixtureName, string $command, array $arguments, string $expected, int $exitCode): void
{
    // Each fixture is the Gateway's 202 reply that returns a finished Doctor Operation, as a replayed request does.
    $fixture = gateway_fixture($fixtureName);
    MockClient::destroyGlobal();
    MockClient::global([
        RunDoctorRequest::class => MockResponse::make($fixture['body'], $fixture['status']),
    ]);
    expect(Artisan::call($command, $arguments))->toBe($exitCode);
    expect_output(Artisan::output(), $expected);
}
