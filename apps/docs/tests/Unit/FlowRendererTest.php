<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

// Draws a chart with renderFlow from docs/flow.js in Node and returns the SVG.
function renderFlowChart(string $chart): string
{
    $script = <<<'JS'
const src = require('node:fs').readFileSync(process.argv[1], 'utf8')
const body = src.slice(src.indexOf('function renderFlow'), src.indexOf('\n  const el ='))
const el = {}
new Function(`${body}\nreturn renderFlow`)()(require('node:fs').readFileSync(0, 'utf8'), el)
process.stdout.write(el.innerHTML)
JS;
    $process = new Process(['node', '-e', $script, dirname(__DIR__, 4).'/docs/flow.js']);
    $process->setInput($chart);
    $process->mustRun();

    return $process->getOutput();
}

it('draws a par frame with an AND tag for each further section', function (): void {
    $svg = renderFlowChart(<<<'MMD'
sequenceDiagram
  participant A as A
  participant B as B
  par checkout beside T3
    A->>B: checkout
  and T3 beside the checkout
    A->>B: t3
  end
MMD);

    expect($svg)->toContain('>PAR</text>')
        ->and($svg)->toContain('>AND</text>')
        ->and($svg)->toContain('>T3 beside the checkout</text>')
        ->and($svg)->not->toContain('>ELSE</text>');
});

it('skips an end without an open frame and a block word it does not know', function (): void {
    $svg = renderFlowChart(<<<'MMD'
sequenceDiagram
  participant A as A
  participant B as B
  critical a word it does not know
    A->>B: call
  end
  end
  else stray
MMD);

    expect($svg)->toContain('>call</text>')
        ->and($svg)->not->toContain('class="frame"');
});
