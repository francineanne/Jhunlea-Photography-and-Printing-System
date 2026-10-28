<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\BookingReferenceImage;
use App\Models\User;

/** A reference image may be seen only by the owner of the shop and by the customer whose booking it belongs to. */
class BookingReferenceImagePolicy
{
    public function view(User $user, BookingReferenceImage $image): bool
    {
        if ($user->role === UserRole::Owner) {
            return true;
        }

        // An image of a booking that was deleted has no booking to check against, so only the owner keeps access
        return $user->role === UserRole::Customer && $image->booking !== null && $image->booking->customer_id === $user->id;
    }
}
