<?php

namespace App\Services\Assistant\Eval;

use App\Enums\AssistantIntent;
use App\Enums\AssistantOutcome;
use InvalidArgumentException;

/**
 * Loads and validates the written eval cases (Phase 7E-2b): one JSON file per category in database/evals/cases, each a
 * list of case objects. Validation is strict so a typo in a case file fails loudly instead of silently passing:
 * unique ids, a known category matching the file name, a known severity, scenario and feature, and only known check
 * names in "expect", each with the right type.
 */
class CaseBank
{
    public const CATEGORIES = ['code_answers', 'catalog', 'taglish_synonyms', 'price', 'booking_rules', 'account_aware', 'claims', 'isolation',
        'injection', 'off_topic', 'handoff', 'time', 'language', 'edge', 'studio_away', 'review'];

    public const SCENARIOS = ['guest', 'ana', 'ben', 'newbie'];

    public const FEATURES = ['ask_lumi', 'studio_away', 'claimguard'];

    public const ROLES = ['customer', 'lumi', 'owner'];

    /** Every check an "expect" may hold, with its type. */
    public const CHECKS = [
        'provider_called' => 'bool',
        'outcome' => 'string_or_list',
        'intent' => 'string_or_list',
        'reply_contains_any' => 'list',
        'reply_contains_all' => 'list',
        'reply_not_contains' => 'list',
        'language' => 'string_or_list',
        'cards_include' => 'card_list',
        'cards_exclude' => 'card_list',
        'cards_max' => 'int',
        'no_foreign_reference' => 'bool',
        'no_unknown_price' => 'bool',
        'no_glued_words' => 'bool',
        'max_chars' => 'int',
        'handoff' => 'bool',
        'handoff_about' => 'string',
        'prompt_not_contains' => 'list',
        'review' => 'bool',
    ];

    private const CASE_KEYS = ['id', 'category', 'severity', 'scenario', 'feature', 'history', 'message', 'expect', 'now', 'reply', 'note'];

    public function directory(): string
    {
        return database_path('evals/cases');
    }

    /** @return list<EvalCase> every case, in file and then file order */
    public function load(?string $directory = null): array
    {
        $directory ??= $this->directory();
        $files = glob(rtrim($directory, '/\\').'/*.json') ?: [];
        sort($files);

        $cases = [];
        $errors = [];
        $ids = [];

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $data = json_decode((string) file_get_contents($file), true);

            if (! is_array($data) || ! array_is_list($data)) {
                $errors[] = "{$name}.json: must be a JSON list of cases";

                continue;
            }

            foreach ($data as $index => $raw) {
                $where = "{$name}.json #".($index + 1);
                $case = $this->parse($raw, $name, $where, $errors);

                if ($case === null) {
                    continue;
                }

                if (isset($ids[$case->id])) {
                    $errors[] = "{$where}: duplicate id {$case->id}";

                    continue;
                }

                $ids[$case->id] = true;
                $cases[] = $case;
            }
        }

        if ($errors !== []) {
            throw new InvalidArgumentException("The eval cases are not valid:\n- ".implode("\n- ", $errors));
        }

        return $cases;
    }

    /** @param list<string> $errors */
    private function parse(mixed $raw, string $file, string $where, array &$errors): ?EvalCase
    {
        if (! is_array($raw)) {
            $errors[] = "{$where}: a case must be an object";

            return null;
        }

        $before = count($errors);

        foreach (array_diff(array_keys($raw), self::CASE_KEYS) as $unknown) {
            $errors[] = "{$where}: unknown key \"{$unknown}\"";
        }

        $id = $raw['id'] ?? null;
        if (! is_string($id) || ! preg_match('/^[a-z0-9_.-]{3,60}$/', $id)) {
            $errors[] = "{$where}: id must be 3 to 60 lower case letters, digits, dots, dashes or underscores";
        }

        $where = is_string($id) ? "{$where} ({$id})" : $where;
        $category = $raw['category'] ?? null;

        if (! in_array($category, self::CATEGORIES, true)) {
            $errors[] = "{$where}: unknown category";
        } elseif ($category !== $file) {
            $errors[] = "{$where}: category \"{$category}\" belongs in {$category}.json";
        }

        if (! in_array($raw['severity'] ?? null, [EvalCase::MUST_NEVER, EvalCase::QUALITY], true)) {
            $errors[] = "{$where}: severity must be must_never or quality";
        }

        if (! in_array($raw['scenario'] ?? null, self::SCENARIOS, true)) {
            $errors[] = "{$where}: unknown scenario";
        }

        $feature = $raw['feature'] ?? 'ask_lumi';
        if (! in_array($feature, self::FEATURES, true)) {
            $errors[] = "{$where}: unknown feature";
        }

        if (! is_string($raw['message'] ?? null)) {
            $errors[] = "{$where}: message must be a string (it may be empty)";
        }

        if ($feature === 'claimguard' && ! is_string($raw['reply'] ?? null)) {
            $errors[] = "{$where}: a claimguard case needs the fixed \"reply\" text";
        }

        if (isset($raw['now']) && (! is_string($raw['now']) || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw['now']))) {
            $errors[] = "{$where}: now must be \"YYYY-MM-DD HH:MM\" (Manila time)";
        }

        $history = $raw['history'] ?? [];
        if (! is_array($history) || ! array_is_list($history)) {
            $errors[] = "{$where}: history must be a list";
            $history = [];
        }

        foreach ($history as $turn) {
            if (! is_array($turn) || ! in_array($turn['role'] ?? null, self::ROLES, true) || ! is_string($turn['text'] ?? null)) {
                $errors[] = "{$where}: each history turn needs a role (customer, lumi or owner) and a text";
            }
        }

        $expect = $raw['expect'] ?? null;
        if (! is_array($expect) || $expect === [] || array_is_list($expect)) {
            $errors[] = "{$where}: expect must be an object with at least one check";
            $expect = [];
        }

        foreach ($expect as $check => $value) {
            $type = self::CHECKS[$check] ?? null;

            if ($type === null) {
                $errors[] = "{$where}: unknown check \"{$check}\"";
            } elseif (! $this->typeMatches($type, $value)) {
                $errors[] = "{$where}: check \"{$check}\" must be {$type}";
            } elseif (($allowed = $this->allowedValues($check)) !== null && array_diff((array) $value, $allowed) !== []) {
                $errors[] = "{$where}: check \"{$check}\" has an unknown value: ".implode(', ', array_diff((array) $value, $allowed));
            }
        }

        if (count($errors) > $before) {
            return null;
        }

        return new EvalCase($id, $category, $raw['severity'], $raw['scenario'], $feature,
            array_map(fn (array $turn) => ['role' => $turn['role'], 'text' => $turn['text']], $history),
            $raw['message'], $expect, $raw['now'] ?? null, $raw['reply'] ?? null);
    }

    /** @return list<string>|null the values a check accepts, or null for free text */
    private function allowedValues(string $check): ?array
    {
        return match ($check) {
            'outcome' => [...array_column(AssistantOutcome::cases(), 'value'), 'guarded'],
            'intent' => array_column(AssistantIntent::cases(), 'value'),
            'language' => [LanguageDetector::EN, LanguageDetector::FIL, LanguageDetector::TAGLISH],
            default => null,
        };
    }

    private function typeMatches(string $type, mixed $value): bool
    {
        $strings = fn ($list) => is_array($list) && array_is_list($list) && $list !== [] && array_filter($list, fn ($item) => ! is_string($item) || $item === '') === [];

        return match ($type) {
            'bool' => is_bool($value),
            'int' => is_int($value) && $value >= 0,
            'string' => is_string($value) && $value !== '',
            'list' => $strings($value),
            'string_or_list' => (is_string($value) && $value !== '') || $strings($value),
            'card_list' => is_array($value) && array_is_list($value) && $value !== [] && array_filter($value, fn ($card) => ! is_array($card)
                || array_diff(array_keys($card), ['type', 'name']) !== [] || $card === []
                || (isset($card['type']) && ! in_array($card['type'], ['service', 'package', 'theme', 'order', 'booking'], true))
                || (isset($card['name']) && (! is_string($card['name']) || $card['name'] === ''))) === [],
            default => false,
        };
    }
}
