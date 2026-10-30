<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may use a conversation (Phase 7B). A customer: only their own. The owner: only studio threads, never an Ask Lumi
 * conversation. Unlike the order and booking policies (403), a denial answers 404, like another person's notification:
 * conversation ids are never confirmed to exist.
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): Response
    {
        return $this->allowed($user, $conversation) ? Response::allow() : Response::denyAsNotFound();
    }

    public function send(User $user, Conversation $conversation): Response
    {
        return $this->view($user, $conversation);
    }

    public function read(User $user, Conversation $conversation): Response
    {
        return $this->view($user, $conversation);
    }

    /** Only the owner clears the "needs the owner" flag, and only on a studio thread. */
    public function resolve(User $user, Conversation $conversation): Response
    {
        return $user->role === UserRole::Owner && $conversation->isStudio() ? Response::allow() : Response::denyAsNotFound();
    }

    private function allowed(User $user, Conversation $conversation): bool
    {
        return match ($user->role) {
            UserRole::Customer => $conversation->customer_id === $user->id,
            UserRole::Owner => $conversation->isStudio(),
        };
    }
}
