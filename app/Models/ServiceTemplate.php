<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'customer_instructions',
        'default_processing_days',
        'default_link_expiry_days',
        'allow_multiple_uploads',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'default_processing_days' => 'integer',
            'default_link_expiry_days' => 'integer',
            'allow_multiple_uploads' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function requiredDocuments(): HasMany
    {
        return $this->hasMany(ServiceTemplateDocument::class)
            ->orderBy('sort_order');
    }
}
