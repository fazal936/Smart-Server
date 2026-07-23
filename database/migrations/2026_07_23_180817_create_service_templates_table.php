<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_templates', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('code', 50)->unique();

            $table->text('description')->nullable();
            $table->text('customer_instructions')->nullable();

            $table->unsignedSmallInteger('default_processing_days')
                ->nullable();

            $table->unsignedSmallInteger('default_link_expiry_days')
                ->default(7);

            $table->boolean('allow_multiple_uploads')
                ->default(true);

            $table->boolean('is_active')
                ->default(true);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('name');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_templates');
    }
};
