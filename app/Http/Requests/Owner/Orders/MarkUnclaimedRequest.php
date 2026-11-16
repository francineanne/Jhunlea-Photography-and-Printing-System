<?php

namespace App\Http\Requests\Owner\Orders;

/** Phase 10B: the shop closes an unpaid order that waited too long for pickup. Only an optional note. */
class MarkUnclaimedRequest extends OrderActionRequest {}
