<?php

namespace App\Services\Assistant;

/**
 * Who is asking: a signed-in user, or a guest known only by a keyed hash of their IP address. The raw IP is never
 * kept, stored or sent anywhere.
 */
final class AssistantActor
{
    private function __construct(
        public readonly ?int $userId,
        public readonly ?string $guestKey,
    ) {}

    public static function user(int $userId): self
    {
        return new self($userId, null);
    }

    public static function guest(string $ip): self
    {
        return new self(null, hash_hmac('sha256', $ip, (string) config('app.key')));
    }

    public function isGuest(): bool
    {
        return $this->userId === null;
    }

    /** The rate limiter key part. */
    public function key(): string
    {
        return $this->userId !== null ? "user:{$this->userId}" : "guest:{$this->guestKey}";
    }
}
