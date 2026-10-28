<?php

namespace App\Http\Requests\Owner\Bookings;

/** Confirmed to Completed, never before the session's start (BookingTransitionService checks it). */
class CompleteBookingRequest extends BookingActionRequest {}
