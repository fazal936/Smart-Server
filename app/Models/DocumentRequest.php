<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentRequest extends Model
{
    use HasFactory;

    public const STATUSES = [
        'draft',
        'waiting_for_documents',
        'partially_received',
        'documents_received',
        'pending_review',
        'action_required',
        'additional_documents_required',
        'ready_for_processing',
        'submitted_to_authority',
        'external_processing',
        'service_approved',
        'completed',
        'cancelled',
        'expired',
    ];

    public const PRIORITIES = [
        'low',
        'normal',
        'high',
        'urgent',
    ];

    public const COMMUNICATION_CHANNELS = [
        'whatsapp',
        'email',
        'copy_link',
        'sms',
    ];

    protected $fillable = [
        'request_number',
        'service_template_id',
        'customer_name',
        'customer_mobile',
        'customer_email',
        'company_name',
        'assigned_to',
        'created_by',
        'status',
        'priority',
        'preferred_channel',
        'due_date',
        'next_action',
        'customer_instructions',
        'internal_notes',
        'processing_days',
        'allow_multiple_uploads',
        'sent_at',
        'viewed_at',
        'documents_received_at',
        'review_started_at',
        'submitted_to_authority_at',
        'service_approved_at',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'processing_days' => 'integer',
            'allow_multiple_uploads' => 'boolean',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'documents_received_at' => 'datetime',
            'review_started_at' => 'datetime',
            'submitted_to_authority_at' => 'datetime',
            'service_approved_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function serviceTemplate(): BelongsTo
    {
        return $this->belongsTo(ServiceTemplate::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentRequestItem::class)
            ->orderBy('sort_order');
    }

    public function uploadLinks(): HasMany
    {
        return $this->hasMany(UploadLink::class)
            ->latest();
    }

    public function uploadedDocuments(): HasMany
    {
        return $this->hasMany(UploadedDocument::class)
            ->latest('uploaded_at');
    }

    public function communications(): HasMany
    {
        return $this->hasMany(CommunicationLog::class)
            ->latest();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(RequestActivity::class)
            ->orderByDesc('occurred_at');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && ! in_array(
                $this->status,
                ['completed', 'cancelled', 'expired'],
                true
            );
    }

    public function isWaitingForCustomer(): bool
    {
        return in_array(
            $this->status,
            [
                'waiting_for_documents',
                'partially_received',
                'additional_documents_required',
            ],
            true
        );
    }

    public function canReceiveUploads(): bool
    {
        return ! in_array(
            $this->status,
            [
                'completed',
                'cancelled',
                'expired',
            ],
            true
        );
    }
}
