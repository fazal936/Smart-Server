<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();

            $table->string('request_number', 30)->unique();

            $table->foreignId('service_template_id')
                ->constrained('service_templates')
                ->restrictOnDelete();

            $table->string('customer_name');
            $table->string('customer_mobile', 30);
            $table->string('customer_email')->nullable();
            $table->string('company_name')->nullable();

            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('status', 50)
                ->default('draft');

            $table->string('priority', 20)
                ->default('normal');

            $table->string('preferred_channel', 20)
                ->default('copy_link');

            $table->date('due_date')->nullable();

            $table->string('next_action')->nullable();

            $table->text('customer_instructions')->nullable();
            $table->text('internal_notes')->nullable();

            $table->unsignedSmallInteger('processing_days')->nullable();

            $table->boolean('allow_multiple_uploads')
                ->default(true);

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('documents_received_at')->nullable();
            $table->timestamp('review_started_at')->nullable();
            $table->timestamp('submitted_to_authority_at')->nullable();
            $table->timestamp('service_approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('priority');
            $table->index('preferred_channel');
            $table->index('due_date');
            $table->index('assigned_to');
            $table->index('customer_mobile');
            $table->index('customer_email');

            $table->index([
                'assigned_to',
                'status',
            ]);

            $table->index([
                'status',
                'due_date',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_requests');
    }
};
