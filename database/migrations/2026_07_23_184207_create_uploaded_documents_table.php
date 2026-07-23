<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploaded_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_request_id')
                ->constrained('document_requests')
                ->cascadeOnDelete();

            $table->foreignId('document_request_item_id')
                ->nullable()
                ->constrained('document_request_items')
                ->nullOnDelete();

            $table->foreignId('upload_link_id')
                ->nullable()
                ->constrained('upload_links')
                ->nullOnDelete();

            /*
             * Staff uploads use uploaded_by.
             * Customer uploads normally leave this field null.
             */
            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('upload_source', 30)
                ->default('customer');

            /*
             * Store uploaded files on a private Laravel filesystem disk.
             * Never expose storage_path directly as a public URL.
             */
            $table->string('storage_disk', 50)
                ->default('local');

            $table->text('storage_path');

            $table->string('original_filename');
            $table->string('stored_filename');

            $table->string('mime_type', 150)
                ->nullable();

            $table->string('extension', 20)
                ->nullable();

            $table->unsignedBigInteger('size_bytes');

            $table->char('checksum_sha256', 64)
                ->nullable();

            /*
             * Supports replacement uploads while preserving history.
             */
            $table->unsignedSmallInteger('version_number')
                ->default(1);

            $table->boolean('is_current')
                ->default(true);

            $table->string('status', 30)
                ->default('pending_review');

            $table->text('review_notes')
                ->nullable();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')
                ->nullable();

            /*
             * Placeholder for future antivirus integration.
             */
            $table->string('security_scan_status', 30)
                ->default('pending');

            $table->timestamp('security_scanned_at')
                ->nullable();

            $table->text('security_scan_notes')
                ->nullable();

            $table->timestamp('uploaded_at')
                ->useCurrent();

            $table->timestamps();
            $table->softDeletes();

            $table->index('document_request_id');
            $table->index('document_request_item_id');
            $table->index('upload_link_id');
            $table->index('status');
            $table->index('security_scan_status');
            $table->index('checksum_sha256');

            $table->index([
                'document_request_item_id',
                'is_current',
            ]);

            $table->index([
                'document_request_id',
                'uploaded_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploaded_documents');
    }
};
