<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Dotenv\Parser\Parser;
use Dotenv\Store\File\Reader;
use Symfony\Component\Process\Process;

it('proves each permitted prefix shape leaves the mail line as an assignment in installed phpdotenv', function (): void {
    $prefixes = ['', " \t", '# no quotes or slashes', '  # note=value', 'EMPTY=', 'KEY=value',
        'KEY_2.part=https://fixture.test', 'APP_NAME="Nick\'s App"', "APP_NAME='Nick \"App\"'", 'EMPTY=""', "EMPTY=''",
        'DOUBLE="hash # and equals = stay inside"', "SINGLE='hash # stays inside'", "\xef\xbb\xbf# fixture"];
    $encoded = [];
    $temporary = tempnam(sys_get_temp_dir(), 'orbit-dotenv-whitelist-');
    try {
        foreach (["\n", "\r\n"] as $ending) {
            foreach ($prefixes as $prefix) {
                $content = $prefix.$ending.'MAIL_MAILER=log'.$ending;
                file_put_contents($temporary, $content);
                $read = array_values(Reader::read([$temporary]))[0];
                $variables = Dotenv::parse($read);
                expect(($variables['MAIL_MAILER'] ?? null) === 'log')->toBeTrue();
                $entries = new Parser()->parse($read);
                $last = array_pop($entries);
                expect($last?->getName() === 'MAIL_MAILER')->toBeTrue();
                $encoded[] = base64_encode($prefix.$ending);
            }
        }
        $script = <<<'PYTHON'
import base64,json,runpy,sys
helper=runpy.run_path(sys.argv[1])
print(json.dumps([helper['prefix_guard_line'](base64.b64decode(item).split(b'\n')[:-1]) for item in json.load(sys.stdin)]))
PYTHON;
        $process = new Process(['python3', '-c', $script, base_path('../../swamp/ansible/instance_mail_line.py')]);
        $process->setInput(json_encode($encoded, JSON_THROW_ON_ERROR));
        $process->mustRun();
        expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(array_fill(0, count($encoded), null));
    } finally {
        unlink($temporary);
    }
});

it('refuses the reviewed CR and comment grouping shapes that consume the mail assignment', function (): void {
    $cases = ["X=it's\rY=\"a'\nMAIL_MAILER=log\nb\"\n", "X='=\"'#\nMAIL_MAILER=log\nb\"\n"];
    $encoded = [];
    foreach ($cases as $content) {
        $variables = Dotenv::parse($content);
        expect(array_key_exists('MAIL_MAILER', $variables))->toBeFalse();
        $encoded[] = base64_encode(explode("\n", $content)[0]);
    }
    $script = <<<'PYTHON'
import base64,json,runpy,sys
helper=runpy.run_path(sys.argv[1])
print(json.dumps([helper['prefix_guard_line']([base64.b64decode(item)]) for item in json.load(sys.stdin)]))
PYTHON;
    $process = new Process(['python3', '-c', $script, base_path('../../swamp/ansible/instance_mail_line.py')]);
    $process->setInput(json_encode($encoded, JSON_THROW_ON_ERROR));
    $process->mustRun();
    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([1, 1]);
});
