<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'quantity')) {
                $table->unsignedInteger('quantity')->default(1)->after('product_id');
            }
            if (!Schema::hasColumn('orders', 'receiver_name')) {
                $table->string('receiver_name')->nullable()->after('status');
            }
            if (!Schema::hasColumn('orders', 'receiver_mobile')) {
                $table->string('receiver_mobile', 20)->nullable()->after('receiver_name');
            }
            if (!Schema::hasColumn('orders', 'postal_code')) {
                $table->string('postal_code', 20)->nullable()->after('receiver_mobile');
            }
            if (!Schema::hasColumn('orders', 'shipping_address')) {
                $table->text('shipping_address')->nullable()->after('postal_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('orders', 'quantity') ? 'quantity' : null,
                Schema::hasColumn('orders', 'receiver_name') ? 'receiver_name' : null,
                Schema::hasColumn('orders', 'receiver_mobile') ? 'receiver_mobile' : null,
                Schema::hasColumn('orders', 'postal_code') ? 'postal_code' : null,
                Schema::hasColumn('orders', 'shipping_address') ? 'shipping_address' : null,
            ]);
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};