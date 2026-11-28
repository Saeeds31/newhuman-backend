<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('consultation_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deposit_amount')->default(500000); // مبلغ بیعانه به تومان
            $table->unsignedSmallInteger('slot_duration_minutes')->default(30);
            $table->unsignedSmallInteger('advance_days')->default(14);
            $table->unsignedTinyInteger('lock_ttl_minutes')->default(10);
            $table->timestamps();
        });

        DB::table('consultation_settings')->insert([
            'deposit_amount' => 500000,
            'slot_duration_minutes' => 30,
            'advance_days' => 14,
            'lock_ttl_minutes' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 0 = شنبه ... 6 = جمعه
        Schema::create('consultation_weekly_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('day_of_week');
            $table->enum('shift', ['morning', 'afternoon']);
            $table->string('start_time', 5); // مثال: 08:30
            $table->string('end_time', 5);   // مثال: 12:00
            $table->enum('session_type', ['online', 'offline', 'both'])->default('both');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $defaults = [];
        for ($d = 0; $d <= 4; $d++) {
            $defaults[] = [
                'day_of_week' => $d,
                'shift' => 'morning',
                'start_time' => '08:30',
                'end_time' => '12:00',
                'session_type' => 'both',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $defaults[] = [
                'day_of_week' => $d,
                'shift' => 'afternoon',
                'start_time' => '16:00',
                'end_time' => '19:30',
                'session_type' => 'both',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        $defaults[] = [
            'day_of_week' => 5, // پنج‌شنبه
            'shift' => 'morning',
            'start_time' => '08:30',
            'end_time' => '12:00',
            'session_type' => 'both',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('consultation_weekly_schedules')->insert($defaults);

        Schema::create('consultation_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_code', 32)->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('full_name');
            $table->string('phone', 20);
            $table->enum('session_type', ['online', 'offline'])->default('online');
            $table->string('topic');
            $table->text('description')->nullable();
            $table->date('booking_date_gregorian')->index();
            $table->string('booking_date_jalali', 12)->index(); // 1405-09-08
            $table->string('booking_time', 5); // 08:30
            $table->unsignedBigInteger('deposit_amount')->default(0); // تومان
            $table->enum('status', [
                'pending_payment',
                'confirmed',
                'manual_booked',
                'blocked',
                'cancelled',
            ])->default('pending_payment')->index();
            $table->enum('source', ['website', 'admin'])->default('website');
            $table->string('payment_gateway', 32)->nullable();
            $table->string('payment_ref_id', 64)->nullable();
            $table->timestamp('locked_until')->nullable()->index();
            $table->timestamps();

            $table->index(['booking_date_gregorian', 'booking_time', 'status'], 'idx_slot_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_bookings');
        Schema::dropIfExists('consultation_weekly_schedules');
        Schema::dropIfExists('consultation_settings');
    }
};