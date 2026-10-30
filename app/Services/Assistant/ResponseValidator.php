<?php

namespace App\Services\Assistant;

use App\Enums\AssistantCardType;
use App\Enums\AssistantIntent;
use App\Services\Assistant\Exceptions\ProviderBadResponse;

/**
 * Checks the model's JSON against the output contract and turns the reply into plain text. Anything outside the
 * contract (not JSON, an unknown intent, no reply) is a bad response, answered with the fallback. Recommendations
 * only need the right shape here; CardBuilder checks that each one exists and is published.
 */
class ResponseValidator
{
    public function parse(string $json): ParsedReply
    {
        $data = json_decode(trim($json), true);

        if (! is_array($data) || ! is_string($data['intent'] ?? null) || ! is_string($data['reply'] ?? null)) {
            throw new ProviderBadResponse('The reply is not the expected JSON.');
        }

        $intent = AssistantIntent::tryFrom($data['intent']) ?? throw new ProviderBadResponse('The reply has an unknown intent.');
        $reply = $this->plainText($data['reply']);

        // An off_topic reply is replaced by the fixed text, so only the other intents need text of their own
        if ($reply === '' && $intent !== AssistantIntent::OffTopic) {
            throw new ProviderBadResponse('The reply is empty.');
        }

        return new ParsedReply($intent, $reply, $this->recommendations($data['recommend'] ?? []));
    }

    /**
     * Plain text only: no HTML, no markdown links or emphasis, and no addresses at all (the system adds the shop's own
     * links on the cards). Trimmed to config assistant.max_reply_chars.
     */
    public function plainText(string $text): string
    {
        $text = strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? '';
        $text = preg_replace('~\b(?:https?|ftp)://\S+|\bwww\.\S+~iu', '', $text) ?? '';
        $text = preg_replace('~\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|net|org|ph|io|co|me|app|dev|info|biz|xyz|link|ly|gg|site|online|shop|store)\b(?:/\S*)?~iu', '', $text) ?? '';
        $text = preg_replace('/\*\*|__|`+|^#{1,6}\s*/mu', '', $text) ?? '';
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? '';
        $text = preg_replace('/ *\n */u', "\n", $text) ?? '';
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? '';

        return trim(mb_substr(trim($text), 0, (int) config('assistant.max_reply_chars')));
    }

    /** @return list<array{type: AssistantCardType, id: int}> */
    private function recommendations(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $valid = [];

        foreach ($items as $item) {
            $type = is_array($item) && is_string($item['type'] ?? null) ? AssistantCardType::tryFrom($item['type']) : null;
            $id = is_array($item) ? ($item['id'] ?? null) : null;

            if ($type !== null && is_int($id) && $id > 0) {
                $valid[$type->value.':'.$id] = ['type' => $type, 'id' => $id];
            }

            // More than enough to fill the cards even when some turn out unpublished
            if (count($valid) === 10) {
                break;
            }
        }

        return array_values($valid);
    }
}
