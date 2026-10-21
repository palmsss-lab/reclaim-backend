<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Staff extends Model
{
    protected $table = 'staff';
    protected $primaryKey = 'staff_id';
    public $timestamps = false;

    protected $fillable = ['full_name'];

    public function receivedItems(): HasMany
    {
        return $this->hasMany(FoundItem::class, 'received_by', 'staff_id');
    }
}