<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forecast_results', function (Blueprint $table) {
            $table->string('artifact_id', 64)->nullable();
            $table->string('dataset_hash', 64)->nullable();
            $table->string('training_cutoff', 7)->nullable();
            $table->string('evaluation_protocol')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('forecast_results', function (Blueprint $table) {
            $table->dropColumn(['artifact_id', 'dataset_hash', 'training_cutoff', 'evaluation_protocol']);
        });
    }
};
