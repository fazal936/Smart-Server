<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_links', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_request_id')
                ->constrained('document_requests')
                ->cascadeOnDelete();

            /*
             * The encrypted token allows authorized staff to copy or resend
             * the same link. Public lookups will use the SHA-256 hash.
             */
            $table->text('token');

            $table->char('token_hash', 64)
                ->unique();

            $table->timestamp('expires_at');

            $table->boolean('allow_multiple_uploads')
                ->default(true);

            $table->unsignedInteger('max_uploads')
                ->nullable();

            $table->unsignedInteger('upload_count')
                ->default(0);

            $table->unsignedInteger('access_count')
                ->default(0);

            $table->timestamp('first_accessed_at')
                ->nullable();

            $table->timestamp('last_accessed_at')
                ->nullable();

            $table->timestamp('invalidated_at')
                ->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('document_request_id');
            $table->index('expires_at');
            $table->index('invalidated_at');

            $table->index([
                'document_request_id',
                'invalidated_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_links');
    }
};
