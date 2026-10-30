<?php

namespace Database\Factories;

use App\Enums\ConversationKind;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conversation> */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => User::factory(),
            'kind' => ConversationKind::Assistant,
            'thread_key' => null,
        ];
    }

    public function studio(): static
    {
        return $this->state(['kind' => ConversationKind::Studio, 'thread_key' => ConversationKind::Studio->threadKey()]);
    }

    public function forCustomer(User $customer): static
    {
        return $this->state(['customer_id' => $customer->id]);
    }
}
