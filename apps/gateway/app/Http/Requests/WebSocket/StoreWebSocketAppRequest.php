<?php

declare(strict_types=1);

namespace App\Http\Requests\WebSocket;

use App\Http\Requests\DatabaseConnections\DatabaseConnectionFieldRules;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\WebSocketApp;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class StoreWebSocketAppRequest extends WebSocketChangeRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:63',
                'regex:'.DatabaseConnectionFieldRules::SLUG_PATTERN,
                Rule::notIn([WebSocketApp::GatewayName]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['name']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function name(): string
    {
        $name = $this->validated('name');
        assert(is_string($name));

        return $name;
    }
}
