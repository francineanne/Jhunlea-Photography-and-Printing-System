<?php

namespace App\Http\Requests\Owner\Orders;

/** The shop cancels an order that is Submitted, Under review or Accepted. Only an optional note, which goes to the history. */
class CancelOrderRequest extends OrderActionRequest {}
