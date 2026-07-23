<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_activities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_request_id')
                ->constrained('document_requests')
                ->cascadeOnDelete();

            /*
             * Staff member responsible for the activity.
             * This remains null for customer or automated system activity.
             */
            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * Supported values:
             * staff, customer, system
             */
            $table->string('actor_type', 20)
                ->default('system');

            /*
             * Examples:
             * request_created
             * request_sent
             * link_opened
             * document_uploaded
             * document_approved
             * document_rejected
             * status_changed
             * communication_sent
             * request_completed
             */
            $table->string('activity_type', 50);

            $table->text('description')
                ->nullable();

            /*
             * Used when the request workflow status changes.
             */
            $table->string('from_status', 50)
                ->nullable();

            $table->string('to_status', 50)
                ->nullable();

            /*
             * Optional reference to the related record, such as:
             * UploadedDocument, UploadLink or CommunicationLog.
             */
            $table->string('subject_type', 150)
                ->nullable();

            $table->unsignedBigInteger('subject_id')
                ->nullable();

            /*
             * Additional non-sensitive activity details.
             */
            $table->json('metadata')
                ->nullable();

            $table->string('ip_address', 45)
                ->nullable();

            $table->text('user_agent')
                ->nullable();

            $table->timestamp('occurred_at')
                ->useCurrent();

            $table->timestamps();

            $table->index([
                'document_request_id',
                'activity_type',
            ]);

            $table->index([
                'document_request_id',
                'occurred_at',
            ]);

            $table->index([
                'subject_type',
                'subject_id',
            ]);

            $table->index('actor_id');
            $table->index('actor_type');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_activities');
    }
};
