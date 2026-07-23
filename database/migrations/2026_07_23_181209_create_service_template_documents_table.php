<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_template_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_template_id')
                ->constrained('service_templates')
                ->cascadeOnDelete();

            $table->string('name');

            $table->text('description')
                ->nullable();

            $table->text('customer_instructions')
                ->nullable();

            $table->boolean('is_required')
                ->default(true);

            $table->unsignedSmallInteger('sort_order')
                ->default(0);

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();

            $table->index([
                'service_template_id',
                'is_active',
            ]);

            $table->index([
                'service_template_id',
                'sort_order',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_template_documents');
    }
};
