<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processed_records', function (Blueprint $table) {
            $table->decimal('lag_1', 15, 2)->nullable()->change();
            $table->decimal('lag_2', 15, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('processed_records', function (Blueprint $table) {
            $table->decimal('lag_1', 15, 2)->nullable(false)->change();
            $table->decimal('lag_2', 15, 2)->nullable(false)->change();
        });
    }
};
