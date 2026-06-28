<?php

declare(strict_types=1);

namespace App\Services\Solo;

use Illuminate\Http\Request;

final readonly class SoloReadOperation
{
    /**
     * @param  list<string>  $requiredQuery
     */
    public function __construct(
        public string $apiPath,
        public string $upstreamTemplate,
        public string $dataKey,
        public array $requiredQuery = [],
    ) {}

    public function activityType(): string
    {
        return 'solo.'.str_replace(search: '/', replace: '.', subject: $this->apiPath).'.read';
    }

    public function upstreamPath(Request $request): string
    {
        $path = $this->upstreamTemplate;

        foreach ($this->requiredQuery as $key) {
            $value = $this->queryString($request, $key);

            if ($value === null) {
                throw new SoloProxyException(
                    errorCode: 'validation_failed',
                    message: "The {$key} query parameter is required.",
                    meta: ['field' => $key],
                    status: 422,
                );
            }

            $path = str_replace('{'.$key.'}', rawurlencode($value), $path);
        }

        if ($this->apiPath === 'process/output') {
            $lines = $this->queryString($request, 'lines') ?? '100';

            return "{$path}?lines=".rawurlencode($lines);
        }

        return $path;
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_scalar($value)) {
            return null;
        }

        $stringValue = (string) $value;

        return $stringValue !== '' ? $stringValue : null;
    }
}
