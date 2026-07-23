<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_request_id',
        'upload_link_id',
        'initiated_by',
        'channel',
        'direction',
        'recipient_name',
        'recipient_address',
        'subject',
        'message_body',
        'template_key',
        'status',
        'provider',
        'provider_message_id',
        'provider_metadata',
        'failure_reason',
        'queued_at',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_metadata' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    public function uploadLink(): BelongsTo
    {
        return $this->belongsTo(UploadLink::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function isOutbound(): bool
    {
        return $this->direction === 'outbound';
    }

    public function isInbound(): bool
    {
        return $this->direction === 'inbound';
    }

    public function wasSuccessful(): bool
    {
        return in_array(
            $this->status,
            ['copied', 'sent', 'delivered', 'read', 'received'],
            true
        );
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }
}
