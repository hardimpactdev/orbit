<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Domain\Analytics\AnalyticsTrackingHosts;
use App\Domain\Routes\RouteDomain;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class EnableInstanceAnalyticsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'hosts' => ['sometimes', 'array', 'list', 'max:'.AnalyticsTrackingHosts::MAXIMUM],
            'hosts.*' => ['required', 'string', 'max:253'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'goals' => ['sometimes', 'array', 'list', 'max:100'],
            'goals.*' => ['array:goal_type,goal'],
            'goals.*.goal_type' => ['required', 'in:Goal.CustomEvent,Goal.Pageview,Goal.Revenue'],
            'goals.*.goal' => ['required', 'array:event_name,path,currency,custom_props'],
            'goals.*.goal.event_name' => ['sometimes', 'string', 'min:1', 'max:2000'],
            'goals.*.goal.path' => ['sometimes', 'string', 'starts_with:/', 'max:2000'],
            'goals.*.goal.currency' => ['sometimes', 'string', 'regex:/\\A[A-Z]{3}\\z/D'],
            'goals.*.goal.custom_props' => ['sometimes', 'array', 'max:3'],
            'goals.*.goal.custom_props.*' => ['string', 'min:1', 'max:2000', 'regex:/\\A[^\\r\\n\\x00]+\\z/D'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        // A PHP client encodes an empty body as an empty list; both mean the default host.
        $content = trim($this->getContent()) === '[]' ? '' : $this->getContent();

        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($content, ['hosts', 'timezone', 'goals']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->goals() as $index => $goal) {
                $fields = ValidatedData::object($goal['goal']);
                $required = match ($goal['goal_type']) {
                    'Goal.Pageview' => ['path'],
                    'Goal.Revenue' => ['event_name', 'currency'],
                    default => ['event_name'],
                };
                if (array_diff($required, array_keys($fields)) !== [] || array_diff(array_keys($fields), [...$required, 'custom_props']) !== []) {
                    $validator->errors()->add("goals.{$index}.goal", 'Supply only the fields required by this goal type.');
                }
                if (isset($fields['custom_props']) && is_array($fields['custom_props']) && (array_is_list($fields['custom_props']) || in_array('', array_keys($fields['custom_props']), true))) {
                    $validator->errors()->add("goals.{$index}.goal.custom_props", 'Goal properties must be an object with named keys.');
                }
                foreach ($fields as $value) {
                    if (is_string($value) && preg_match('/[\\r\\n\\x00]/', $value) === 1) {
                        $validator->errors()->add("goals.{$index}.goal", 'Goal text must contain no control characters.');
                    }
                }
            }

            $hosts = $this->hosts();

            foreach ($hosts as $index => $host) {
                $reason = AnalyticsTrackingHosts::reason($host);

                if ($reason !== null) {
                    $validator->errors()->add("hosts.{$index}", $reason);
                }
            }

            if (count($hosts) !== count(array_unique($hosts))) {
                $validator->errors()->add('hosts', 'Name each tracking host once.');
            }
        }];
    }

    public function timezone(): string
    {
        return ValidatedData::string($this->validated('timezone', 'Europe/Amsterdam'));
    }

    /** @return list<array<string,mixed>> */
    public function goals(): array
    {
        return array_values(array_map(static function (mixed $value): array {
            $goal = ValidatedData::object($value);
            $fields = ValidatedData::object($goal['goal'] ?? []);
            if (($fields['custom_props'] ?? null) === []) {
                unset($fields['custom_props']);
            }

            return [...$goal, 'goal' => $fields];
        }, $this->array('goals')));
    }

    /** @return list<string> The normalised hosts, in request order. */
    public function hosts(): array
    {
        $hosts = $this->validationData()['hosts'] ?? [];

        return is_array($hosts)
            ? array_values(array_map(
                static fn (mixed $host): string => RouteDomain::normalize(is_string($host) ? $host : ''),
                $hosts,
            ))
            : [];
    }
}
