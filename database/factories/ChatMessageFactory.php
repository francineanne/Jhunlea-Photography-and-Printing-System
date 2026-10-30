<?php

namespace Database\Factories;

use App\Enums\MessageSender;
use App\Models\ChatMessage;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChatMessage> */
class ChatMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'sender_type' => MessageSender::Customer,
            'sender_id' => null,
            'body' => fake()->sentence(),
            'meta' => null,
            'created_at' => now(),
        ];
    }

    public function from(MessageSender $sender, ?int $senderId = null): static
    {
        return $this->state(['sender_type' => $sender, 'sender_id' => $senderId]);
    }
}
