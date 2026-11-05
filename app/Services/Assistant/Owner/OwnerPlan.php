<?php

namespace App\Services\Assistant\Owner;

/**
 * The planning call's answer, checked (Phase 7E-3a): "tools" with 1 to max_tools known tools and valid parameters, a
 * clarifying question, out_of_scope, or write_request. Anything else is an InvalidToolCall and nothing is executed.
 */
final readonly class OwnerPlan
{
    public const TOOLS = 'tools';

    public const CLARIFY = 'clarify';

    public const OUT_OF_SCOPE = 'out_of_scope';

    public const WRITE_REQUEST = 'write_request';

    /** @param list<array{tool: OwnerTool, params: array<string, mixed>}> $calls */
    public function __construct(
        public string $action,
        public array $calls = [],
        public ?string $question = null,
    ) {}

    public static function parse(string $json, OwnerTools $tools): self
    {
        $data = json_decode(trim($json), true);

        if (! is_array($data) || ! in_array($data['action'] ?? null, [self::TOOLS, self::CLARIFY, self::OUT_OF_SCOPE, self::WRITE_REQUEST], true)) {
            throw new InvalidToolCall('the plan is not the expected JSON');
        }

        $items = is_array($data['tools'] ?? null) ? array_values($data['tools']) : [];

        if ($data['action'] === self::CLARIFY) {
            $question = is_string($data['question'] ?? null) ? trim($data['question']) : '';

            if ($question === '') {
                throw new InvalidToolCall('a clarification without a question');
            }

            return new self(self::CLARIFY, question: mb_substr($question, 0, 300));
        }

        if ($data['action'] === self::OUT_OF_SCOPE) {
            return new self(self::OUT_OF_SCOPE);
        }

        if (count($items) > (int) config('assistant.owner.max_tools') || ($data['action'] === self::TOOLS && $items === [])) {
            throw new InvalidToolCall('too many or no tools');
        }

        $calls = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['name'] ?? null)) {
                throw new InvalidToolCall('a tool without a name');
            }

            $raw = $item['params_json'] ?? ($item['params'] ?? []);
            $params = is_string($raw) ? json_decode(trim($raw) === '' ? '{}' : trim($raw), true) : $raw;

            if (! is_array($params) || ($params !== [] && array_is_list($params))) {
                throw new InvalidToolCall('the parameters are not a JSON object');
            }

            $tool = $tools->get($item['name']);
            $calls[] = ['tool' => $tool, 'params' => $tool->validate($params)];
        }

        // A write request may only bring the how-to steps that point to the page doing it
        if ($data['action'] === self::WRITE_REQUEST) {
            $calls = array_values(array_filter($calls, fn (array $call) => $call['tool']->name() === 'how_to'));
        }

        return new self($data['action'], $calls);
    }

    /** @return list<string> */
    public function toolNames(): array
    {
        return array_map(fn (array $call) => $call['tool']->name(), $this->calls);
    }
}
