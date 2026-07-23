<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceTemplateDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_template_id',
        'name',
        'description',
        'customer_instructions',
        'is_required',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function serviceTemplate(): BelongsTo
    {
        return $this->belongsTo(ServiceTemplate::class);
    }
}
