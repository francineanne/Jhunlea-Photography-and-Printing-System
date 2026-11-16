<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10B (payment rules). Safe on a database that holds rows: it only ADDS columns, swaps one unique index for a
 * wider one and lets two history columns be empty. No booking, order or payment changes status, and nothing is
 * cancelled.
 *
 * 1. payments.kind (full, downpayment, balance). Every existing row becomes `full` (the column default). The unique
 *    index (payable, active) becomes (payable, kind, active): one active payment of each kind per order or booking.
 *    PaymentService decides which kinds may exist together, on the locked order or booking row.
 * 2. photography_bookings.downpayment_due_at (UTC): until when an "Awaiting downpayment" booking holds its time.
 *    Existing Confirmed bookings stay Confirmed and get no deadline (their full price is due on the shoot day).
 * 3. printing_orders.requires_prepayment: set when the shop accepts; existing orders keep pay-on-pickup (false).
 * 4. users.prepay_required: set when an order of the customer is marked Unclaimed; nobody is flagged by this migration.
 * 5. shop_settings: the payment rules and the GCash name and number shown to customers (NULL = the config default).
 * 6. order_status_histories.changed_by and booking_status_histories.changed_by may be NULL: a step the system took
 *    by itself (a booking cancelled because its downpayment was not paid by the deadline) shows "System".
 *
 * down() drops the new columns and restores the old payments index. It refuses while two active payments exist for one
 * order or booking (a downpayment and its balance), because the old index could not hold them.
 */
return new class extends Migration
{
    private const OLD_UNIQUE = 'payments_payable_type_payable_id_active_unique';

    private const NEW_UNIQUE = 'payments_payable_kind_active_unique';

    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('kind', 16)->default('full')->after('amount');
        });

        // Added before the old one is dropped, so the table is never without a guard
        Schema::table('payments', function (Blueprint $table) {
            $table->unique(['payable_type', 'payable_id', 'kind', 'active'], self::NEW_UNIQUE);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(self::OLD_UNIQUE);
        });

        Schema::table('photography_bookings', function (Blueprint $table) {
            $table->dateTime('downpayment_due_at')->nullable()->after('hold_expires_at');
            $table->index(['status', 'downpayment_due_at'], 'photography_bookings_status_downpayment_index');
        });

        Schema::table('printing_orders', function (Blueprint $table) {
            $table->boolean('requires_prepayment')->default(false)->after('confirmed_price');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('prepay_required')->default(false)->after('is_walk_in');
        });

        Schema::table('shop_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('downpayment_percent')->nullable();
            $table->unsignedSmallInteger('downpayment_due_days')->nullable();
            $table->unsignedSmallInteger('downpayment_min_hours')->nullable();
            $table->decimal('prepay_from', 10, 2)->nullable();
            $table->unsignedSmallInteger('unclaimed_after_days')->nullable();
            $table->string('gcash_name', 80)->nullable();
            $table->string('gcash_number', 20)->nullable();
        });

        foreach (['order_status_histories', 'booking_status_histories'] as $history) {
            Schema::table($history, function (Blueprint $table) {
                $table->unsignedBigInteger('changed_by')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        $doubled = DB::table('payments')->whereNotNull('active')
            ->select('payable_type', 'payable_id')->groupBy('payable_type', 'payable_id')->havingRaw('COUNT(*) > 1')->exists();

        if ($doubled) {
            throw new RuntimeException('Some orders or bookings have a downpayment and a balance recorded. The old payments index cannot hold them, so this migration was not reverted.');
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->unique(['payable_type', 'payable_id', 'active'], self::OLD_UNIQUE);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(self::NEW_UNIQUE);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        Schema::table('photography_bookings', function (Blueprint $table) {
            $table->dropIndex('photography_bookings_status_downpayment_index');
        });
        Schema::table('photography_bookings', function (Blueprint $table) {
            $table->dropColumn('downpayment_due_at');
        });
        Schema::table('printing_orders', function (Blueprint $table) {
            $table->dropColumn('requires_prepayment');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('prepay_required');
        });
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->dropColumn(['downpayment_percent', 'downpayment_due_days', 'downpayment_min_hours', 'prepay_from', 'unclaimed_after_days', 'gcash_name', 'gcash_number']);
        });
        // changed_by stays nullable: rows written by the system would break a NOT NULL column
    }
};
