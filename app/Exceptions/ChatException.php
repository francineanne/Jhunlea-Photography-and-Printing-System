<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A refused chat action (Phase 7B), answered as JSON {code, message} with its HTTP status. Nothing was stored. */
class ChatException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function invalidMessage(): self
    {
        return new self('invalid_message', str_replace(':max', (string) config('chat.message_max_chars'), config('chat.messages.invalid_message')), 422);
    }

    public static function invalid(string $code): self
    {
        return new self($code, config("chat.messages.{$code}"), 422);
    }

    public static function waitingForStudio(): self
    {
        return new self('waiting_for_studio', config('chat.messages.waiting_for_studio'), 429);
    }

    public static function dailyCap(): self
    {
        return new self('daily_cap', config('chat.messages.daily_cap'), 429);
    }

    public static function tooManyConversations(): self
    {
        return new self('too_many_conversations', config('chat.messages.too_many_conversations'), 429);
    }

    public static function conversationFull(): self
    {
        return new self('conversation_full', config('chat.messages.conversation_full'), 409);
    }

    /** A refusal is an answer, not an error: nothing is written to the log. */
    public function report(): void {}

    public function render(): JsonResponse
    {
        return response()->json(['code' => $this->errorCode, 'message' => $this->getMessage()], $this->status);
    }
}
