<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('payment_expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('provider_transaction_id', 128);
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('status', 32);
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['payment_expires_at', 'paid_at']);
        });
    }
};
