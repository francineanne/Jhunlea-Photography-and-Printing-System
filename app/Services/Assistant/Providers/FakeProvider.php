<?php

namespace App\Services\Assistant\Providers;

use Throwable;

/**
 * Scripted replies for tests and local development: no network, no key. Queue raw JSON strings or exceptions with
 * push(); when the queue is empty it answers with a plain "answer". Every request is kept so tests can read the prompt.
 */
class FakeProvider implements AssistantProvider
{
    /** @var list<string|Throwable> */
    private array $queue = [];

    /** @var list<ProviderRequest> */
    public array $requests = [];

    public function push(string|Throwable ...$replies): static
    {
        array_push($this->queue, ...$replies);

        return $this;
    }

    /** @param array<string, mixed> $reply */
    public function pushJson(array $reply): static
    {
        return $this->push(json_encode($reply, JSON_THROW_ON_ERROR));
    }

    public function lastRequest(): ?ProviderRequest
    {
        return $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
    }

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake';
    }

    public function generate(ProviderRequest $request): ProviderResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue)
            ?? json_encode(['intent' => 'answer', 'reply' => 'This is a scripted reply from the fake provider.', 'recommend' => []], JSON_THROW_ON_ERROR);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return new ProviderResponse($next, 100, 20);
    }
}
