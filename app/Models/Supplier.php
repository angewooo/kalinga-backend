<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $primaryKey = 'supplier_id';
    
    protected $casts = [
        'contact_info' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $fillable = [
        'name', 
        'contact_info', 
        'address',
        'status' // Consider adding if not exists
    ];

    // Validation rules
    public static $rules = [
        'name' => 'required|string|max:100',
        'contact_info' => 'nullable|array',
        'address' => 'nullable|string'
    ];

    public function resourceBatches(): HasMany
    {
        return $this->hasMany(ResourceBatch::class, 'supplier_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SupplyOrder::class, 'supplier_id');
    }

    // Helper methods
    public function getEmailAttribute(): ?string
    {
        return $this->contact_info['email'] ?? null;
    }

    public function getContactPersonAttribute(): ?string
    {
        return $this->contact_info['person'] ?? null;
    }

    public function isActive(): bool
    {
        return $this->status === 'active'; // If you add status field
    }
}