<?php

declare(strict_types=1);

namespace App\Commands\Solo;

use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\table;

final readonly class SoloReadOnlyHumanRenderer
{
    public function __construct(
        private SoloResponseDataNormalizer $normalizer,
        private SoloHumanValueFormatter $formatter,
    ) {}

    /**
     * @param  array<string, mixed>  $response
     */
    public function render(Command $command, array $response): void
    {
        $data = $this->normalizer->successData($response);

        if ($data === []) {
            $command->line('OK');

            return;
        }

        $list = $this->firstList($data);

        if ($list !== null) {
            $this->renderList($list);

            return;
        }

        foreach ($data as $key => $value) {
            $command->line($this->formatter->key((string) $key).': '.$this->formatter->value($value));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>|null
     */
    private function firstList(array $data): ?array
    {
        foreach ($data as $value) {
            $rows = $this->normalizer->arrayRows($value);

            if ($rows === []) {
                continue;
            }

            return $rows;
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderList(array $rows): void
    {
        $headers = array_slice(array_keys($rows[0]), offset: 0, length: 4);

        table(
            headers: array_map(
                static fn (string $header): string => strtoupper(str_replace(
                    search: '_',
                    replace: ' ',
                    subject: $header,
                )),
                $headers,
            ),
            rows: array_map(fn (array $row): array => array_map(
                fn (string $header): string => $this->formatter->value($row[$header] ?? null),
                $headers,
            ), $rows),
        );
    }
}
