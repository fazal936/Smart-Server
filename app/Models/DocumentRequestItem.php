<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentRequestItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_request_id',
        'service_template_document_id',
        'name',
        'description',
        'customer_instructions',
        'is_required',
        'sort_order',
        'status',
        'review_notes',
        'received_at',
        'approved_at',
        'rejected_at',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'sort_order' => 'integer',
            'received_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    public function serviceTemplateDocument(): BelongsTo
    {
        return $this->belongsTo(ServiceTemplateDocument::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function uploadedDocuments(): HasMany
    {
        return $this->hasMany(UploadedDocument::class);
    }
}
