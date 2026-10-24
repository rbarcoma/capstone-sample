<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

test('provenance migration preserves legacy values and leaves their origin unavailable', function () {
    // Dedicated in-memory connection, even when the main test suite uses MySQL.
    $original = DB::getDefaultConnection();
    config(['database.connections.provenance_migration_fixture' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]]);
    DB::setDefaultConnection('provenance_migration_fixture');
    try {
        Schema::create('forecast_results', function (Blueprint $table) {
            $table->id();
            $table->integer('year');
            $table->integer('month');
            $table->decimal('predicted_consumption_kwh', 14, 2);
            $table->string('model_type');
            $table->timestamps();
        });
        DB::table('forecast_results')->insert([
            'year' => 2026, 'month' => 11, 'predicted_consumption_kwh' => 999,
            'model_type' => 'Legacy saved label', 'created_at' => '2026-01-01 00:00:00',
        ]);
        $before = (array) DB::table('forecast_results')->first();
        $migration = require base_path('database/migrations/2026_10_23_000001_add_forecast_artifact_provenance.php');
        $migration->up();
        $after = (array) DB::table('forecast_results')->first();
        expect(array_intersect_key($after, $before))->toBe($before);
        foreach (['artifact_id', 'dataset_hash', 'training_cutoff', 'evaluation_protocol'] as $field) {
            expect(array_key_exists($field, $after))->toBeTrue();
            expect($after[$field])->toBeNull();
        }
        $migration->down();
        expect((array) DB::table('forecast_results')->first())->toBe($before);
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('provenance_migration_fixture');
    }
});
