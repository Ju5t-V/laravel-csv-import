<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('original_name', 255);
            $table->string('stored_path');
            $table->char('sha256', 64)->index();
            $table->unsignedBigInteger('size_bytes');
            $table->string('status', 20)->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('inserted_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            // Resume checkpoint: CSV records already committed (separate from progress).
            $table->unsignedInteger('processed_offset')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
