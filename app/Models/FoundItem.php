<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FoundItem extends Model
{
    protected $table = 'found_item';
    protected $primaryKey = 'item_id';
    public $timestamps = false;
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'finder_id',
        'category_id',
        'item_description',
        'found_place',
        'found_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'found_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }

    public function finder(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'finder_id', 'person_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'received_by', 'staff_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id', 'category_id');
    }
}