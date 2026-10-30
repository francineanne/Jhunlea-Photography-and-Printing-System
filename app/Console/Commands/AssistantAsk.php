<?php

namespace App\Console\Commands;

use App\Enums\AssistantFeature;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\AssistantEngine;
use App\Services\Assistant\AssistantInput;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * A developer tool to try the assistant from the terminal with the configured provider. It prints only the result:
 * never the key and never the prompt. It refuses to run in production.
 */
#[Signature('assistant:ask {message : What a customer would type} {--feature=ask_lumi : ask_lumi, studio_away, owner_assist_suggest or owner_assist_summarize}')]
#[Description('Ask the AI assistant one question and print its reply (development only)')]
class AssistantAsk extends Command
{
    public function handle(AssistantEngine $engine): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('assistant:ask is a development tool and does not run in production.');

            return self::FAILURE;
        }

        $feature = AssistantFeature::tryFrom((string) $this->option('feature'));

        if ($feature === null) {
            $this->error('Unknown feature. Use one of: '.implode(', ', array_column(AssistantFeature::cases(), 'value')).'.');

            return self::FAILURE;
        }

        // The terminal counts as one guest, so its calls use the guest daily limit like any visitor
        $result = $engine->handle(new AssistantInput($feature, AssistantActor::guest('assistant:ask'), (string) $this->argument('message')));

        $this->line('<info>Reply:</info> '.$result->reply);
        $this->line('<info>Intent:</info> '.($result->intent?->value ?? '-'));
        $this->line('<info>Outcome:</info> '.$result->outcome->value);
        $this->line('<info>Needs owner:</info> '.($result->needsOwner ? 'yes' : 'no'));

        if ($result->cards !== []) {
            $this->table(['Type', 'Id', 'Name', 'Price', 'Link'], array_map(
                fn (array $card) => [$card['type'], $card['id'], $card['name'], $card['price'] ?? '-', $card['url']],
                $result->cards,
            ));
        }

        return self::SUCCESS;
    }
}
