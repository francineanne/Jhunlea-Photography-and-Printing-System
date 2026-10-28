<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\OrderFile;
use App\Models\User;

/** A file may be seen only by the owner of the shop and by the customer whose order it belongs to. */
class OrderFilePolicy
{
    public function view(User $user, OrderFile $file): bool
    {
        if ($user->role === UserRole::Owner) {
            return true;
        }

        return $user->role === UserRole::Customer && $file->order->customer_id === $user->id;
    }
}
