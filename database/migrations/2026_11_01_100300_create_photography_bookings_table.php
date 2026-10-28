<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photography_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 20)->unique();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('photography_package_id')->constrained('photography_packages')->restrictOnDelete();
            $table->foreignId('theme_id')->nullable()->constrained('themes')->nullOnDelete();
            $table->string('custom_theme_text', 500)->nullable();
            // The package, its price wording, inclusions and the theme name as they were when the booking was requested
            $table->json('snapshot');
            // The session the booking is for (UTC). While requested, the time asked for; once confirmed, the agreed time
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            // What the customer first asked for, kept for the history even after the time changes
            $table->dateTime('requested_starts_at');
            $table->unsignedSmallInteger('group_size');
            $table->string('contact_number', 30);
            $table->text('notes')->nullable();
            // The studio's private note. Never shown to customers and never written to the history
            $table->text('internal_notes')->nullable();
            $table->boolean('wants_prints')->default(false);
            $table->string('status', 32)->default('requested');
            $table->decimal('estimated_price', 10, 2)->nullable();
            $table->decimal('confirmed_price', 10, 2)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->dateTime('proposed_starts_at')->nullable();
            $table->text('proposed_note')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'starts_at']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photography_bookings');
    }
};
