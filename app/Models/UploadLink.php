<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UploadLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_request_id',
        'token',
        'token_hash',
        'expires_at',
        'allow_multiple_uploads',
        'max_uploads',
        'upload_count',
        'access_count',
        'first_accessed_at',
        'last_accessed_at',
        'invalidated_at',
        'created_by',
    ];

    protected $hidden = [
        'token',
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'expires_at' => 'datetime',
            'allow_multiple_uploads' => 'boolean',
            'max_uploads' => 'integer',
            'upload_count' => 'integer',
            'access_count' => 'integer',
            'first_accessed_at' => 'datetime',
            'last_accessed_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function uploadedDocuments(): HasMany
    {
        return $this->hasMany(UploadedDocument::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isInvalidated(): bool
    {
        return $this->invalidated_at !== null;
    }

    public function hasReachedUploadLimit(): bool
    {
        if (! $this->allow_multiple_uploads) {
            return $this->upload_count >= 1;
        }

        if ($this->max_uploads === null) {
            return false;
        }

        return $this->upload_count >= $this->max_uploads;
    }

    public function isUsable(): bool
    {
        return ! $this->isExpired()
            && ! $this->isInvalidated()
            && ! $this->hasReachedUploadLimit();
    }
}
