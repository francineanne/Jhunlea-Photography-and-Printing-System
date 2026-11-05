<?php

namespace App\Services\Assistant\Owner;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * One read-only tool of the owner's Ask Lumi (Phase 7E-3a). A tool has a name, a one-line description and a parameter
 * schema; execute() rejects an unknown or invalid parameter (InvalidToolCall) and then calls handle(), which only
 * READS through the existing services and returns a ToolResult. A tool never writes anything, never returns a phone
 * number, an email or a message text, and the model never writes SQL: it only picks a tool and its parameters.
 *
 * The schema: name => ['type' => string|integer|boolean|date, 'enum' => [...], 'min', 'max', 'max_length',
 * 'required' => bool, 'description' => '...']. The validation rules are built from it, so there is one source.
 */
abstract class OwnerTool
{
    public const MAX_ROWS = 10;

    abstract public function name(): string;

    abstract public function description(): string;

    /** @return array<string, array<string, mixed>> */
    public function parameters(): array
    {
        return [];
    }

    /** @param array<string, mixed> $params validated */
    abstract protected function handle(array $params, User $owner): ToolResult;

    /** @param array<string, mixed> $params */
    public function execute(array $params, User $owner): ToolResult
    {
        $valid = $this->validate($params);

        try {
            return $this->handle($valid, $owner);
        } catch (AmbiguousDateRange $question) {
            return new ToolResult($this->name(), question: $question->getMessage());
        }
    }

    /** @param array<string, mixed> $params */
    protected function range(array $params, string $default = 'today'): DateRange
    {
        return app(DateRangeResolver::class)->resolve($params, $default);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function validate(array $params): array
    {
        $schema = $this->parameters();
        $unknown = array_diff(array_keys($params), array_keys($schema));

        if ($unknown !== []) {
            throw new InvalidToolCall($this->name().': unknown parameter '.implode(', ', $unknown));
        }

        $validator = Validator::make($params, array_map(fn (array $spec) => $this->rules($spec), $schema));

        if ($validator->fails()) {
            throw new InvalidToolCall($this->name().': '.implode(' ', array_keys($validator->errors()->messages())).' invalid');
        }

        $valid = $validator->validated();

        foreach ($schema as $name => $spec) {
            if (! array_key_exists($name, $valid) && array_key_exists('default', $spec)) {
                $valid[$name] = $spec['default'];
            }
        }

        return $valid;
    }

    /** The catalog line the planning call reads. */
    public function catalogLine(): string
    {
        $params = [];

        foreach ($this->parameters() as $name => $spec) {
            $type = isset($spec['enum']) ? implode('|', $spec['enum']) : $spec['type'];
            $params[] = $name.(($spec['required'] ?? false) ? '' : '?').': '.$type.(isset($spec['description']) ? ' ('.$spec['description'].')' : '');
        }

        return '- '.$this->name().': '.$this->description().($params === [] ? ' No parameters.' : ' Parameters: '.implode('; ', $params).'.');
    }

    /** The date range parameters most tools share (DateRangeResolver reads them). @return array<string, array<string, mixed>> */
    protected static function rangeParameters(string $default = 'today'): array
    {
        return [
            'range' => ['type' => 'string', 'enum' => DateRangeResolver::PRESETS, 'description' => "default {$default}"],
            'from' => ['type' => 'date', 'description' => 'YYYY-MM-DD, with to, instead of range'],
            'to' => ['type' => 'date', 'description' => 'YYYY-MM-DD, inclusive'],
        ];
    }

    /** @param array<string, mixed> $spec */
    private function rules(array $spec): array
    {
        $rules = [($spec['required'] ?? false) ? 'required' : 'nullable'];

        $rules[] = match ($spec['type']) {
            'integer' => 'integer',
            'boolean' => 'boolean',
            'date' => 'date_format:Y-m-d',
            default => 'string',
        };

        if (isset($spec['enum'])) {
            $rules[] = Rule::in($spec['enum']);
        }

        if ($spec['type'] === 'string') {
            $rules[] = 'max:'.($spec['max_length'] ?? 80);
        }

        if (isset($spec['min'])) {
            $rules[] = 'min:'.$spec['min'];
        }

        if (isset($spec['max'])) {
            $rules[] = 'max:'.$spec['max'];
        }

        return $rules;
    }
}
