<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RequestActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_request_id',
        'actor_id',
        'actor_type',
        'activity_type',
        'description',
        'from_status',
        'to_status',
        'subject_type',
        'subject_id',
        'metadata',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isStaffActivity(): bool
    {
        return $this->actor_type === 'staff';
    }

    public function isCustomerActivity(): bool
    {
        return $this->actor_type === 'customer';
    }

    public function isSystemActivity(): bool
    {
        return $this->actor_type === 'system';
    }

    public function isStatusChange(): bool
    {
        return $this->activity_type === 'status_changed';
    }
}
