<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where Rsc::changed() keeps versions when `rsc.versions` is `database`. The
 * same table and columns Go and the JavaScript stores use, so something
 * outside Laravel can write to it too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('rsc.versions_table', 'rsc_versions'), function (Blueprint $table) {
            $table->string('name')->primary();
            $table->unsignedBigInteger('version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('rsc.versions_table', 'rsc_versions'));
    }
};
