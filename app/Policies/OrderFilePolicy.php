<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\OrderFile;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** A file may be seen only by the owner of the shop and by the customer whose order it belongs to. */
class OrderFilePolicy
{
    public function view(User $user, OrderFile $file): bool
    {
        if ($user->role === UserRole::Owner) {
            return true;
        }

        // A file of an order that was deleted has no order to check against, so only the owner keeps access
        return $user->role === UserRole::Customer && $file->order !== null && $file->order->customer_id === $user->id;
    }

    /**
     * A customer may remove a file they uploaded themselves, on their own order, while the order still takes files.
     * Files the shop added stay. "Not the last file" is checked by PrintingOrderService, which also checks all of
     * this again inside its transaction. A refusal because of the status has the code "closed", so a page that is
     * out of date can show a friendly message instead of an error page.
     */
    public function removeFile(User $user, OrderFile $file): Response
    {
        $order = $file->order;

        if ($user->role !== UserRole::Customer || $order === null || $order->customer_id !== $user->id || $file->uploaded_by !== $user->id) {
            return Response::deny();
        }

        return $order->status->acceptsFiles() ? Response::allow() : Response::deny(code: 'closed');
    }
}
