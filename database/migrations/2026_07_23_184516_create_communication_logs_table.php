<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_request_id')
                ->constrained('document_requests')
                ->cascadeOnDelete();

            $table->foreignId('upload_link_id')
                ->nullable()
                ->constrained('upload_links')
                ->nullOnDelete();

            $table->foreignId('initiated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * Supported values:
             * whatsapp, email, sms, copy_link
             */
            $table->string('channel', 30);

            /*
             * Supported values:
             * outbound, inbound
             */
            $table->string('direction', 20)
                ->default('outbound');

            /*
             * Customer or recipient details at the time the message was sent.
             */
            $table->string('recipient_name')
                ->nullable();

            $table->string('recipient_address');

            $table->string('subject')
                ->nullable();

            $table->text('message_body')
                ->nullable();

            /*
             * Optional identifier for reusable communication templates.
             */
            $table->string('template_key', 100)
                ->nullable();

            /*
             * Supported values:
             * prepared, queued, copied, sent, delivered,
             * read, failed, received
             */
            $table->string('status', 30)
                ->default('prepared');

            /*
             * Examples:
             * meta_whatsapp, smtp, manual, future_sms_provider
             */
            $table->string('provider', 50)
                ->nullable();

            $table->string('provider_message_id')
                ->nullable();

            /*
             * Stores non-sensitive provider response metadata.
             */
            $table->json('provider_metadata')
                ->nullable();

            $table->text('failure_reason')
                ->nullable();

            $table->timestamp('queued_at')
                ->nullable();

            $table->timestamp('sent_at')
                ->nullable();

            $table->timestamp('delivered_at')
                ->nullable();

            $table->timestamp('read_at')
                ->nullable();

            $table->timestamp('failed_at')
                ->nullable();

            $table->timestamp('received_at')
                ->nullable();

            $table->timestamps();

            $table->index([
                'document_request_id',
                'channel',
            ]);

            $table->index([
                'document_request_id',
                'status',
            ]);

            $table->index([
                'channel',
                'status',
            ]);

            $table->index('provider_message_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_logs');
    }
};
