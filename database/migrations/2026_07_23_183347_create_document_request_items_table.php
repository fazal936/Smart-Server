<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_request_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_request_id')
                ->constrained('document_requests')
                ->cascadeOnDelete();

            $table->foreignId('service_template_document_id')
                ->nullable()
                ->constrained('service_template_documents')
                ->nullOnDelete();

            $table->string('name');

            $table->text('description')
                ->nullable();

            $table->text('customer_instructions')
                ->nullable();

            $table->boolean('is_required')
                ->default(true);

            $table->unsignedSmallInteger('sort_order')
                ->default(0);

            $table->string('status', 30)
                ->default('pending');

            $table->text('review_notes')
                ->nullable();

            $table->timestamp('received_at')
                ->nullable();

            $table->timestamp('approved_at')
                ->nullable();

            $table->timestamp('rejected_at')
                ->nullable();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index([
                'document_request_id',
                'status',
            ]);

            $table->index([
                'document_request_id',
                'sort_order',
            ]);

            $table->index('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_request_items');
    }
};
