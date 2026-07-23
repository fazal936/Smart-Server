<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UploadedDocument extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'document_request_id',
        'document_request_item_id',
        'upload_link_id',
        'uploaded_by',
        'upload_source',
        'storage_disk',
        'storage_path',
        'original_filename',
        'stored_filename',
        'mime_type',
        'extension',
        'size_bytes',
        'checksum_sha256',
        'version_number',
        'is_current',
        'status',
        'review_notes',
        'reviewed_by',
        'reviewed_at',
        'security_scan_status',
        'security_scanned_at',
        'security_scan_notes',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'version_number' => 'integer',
            'is_current' => 'boolean',
            'reviewed_at' => 'datetime',
            'security_scanned_at' => 'datetime',
            'uploaded_at' => 'datetime',
        ];
    }

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(
            DocumentRequestItem::class,
            'document_request_item_id'
        );
    }

    public function uploadLink(): BelongsTo
    {
        return $this->belongsTo(UploadLink::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isCustomerUpload(): bool
    {
        return $this->upload_source === 'customer';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function hasPassedSecurityScan(): bool
    {
        return $this->security_scan_status === 'clean';
    }

    public function humanReadableSize(): string
    {
        $bytes = max(0, $this->size_bytes);

        if ($bytes >= 1_073_741_824) {
            return number_format($bytes / 1_073_741_824, 2).' GB';
        }

        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 2).' MB';
        }

        if ($bytes >= 1_024) {
            return number_format($bytes / 1_024, 2).' KB';
        }

        return $bytes.' bytes';
    }
}
