<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends Model
{
    protected $table = 'person';
    protected $primaryKey = 'person_id';
    public $timestamps = false;

    protected $fillable = ['full_name', 'contact_details'];

    public function foundItems(): HasMany
    {
        return $this->hasMany(FoundItem::class, 'finder_id', 'person_id');
    }
}