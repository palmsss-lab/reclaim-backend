<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Person extends Model
{
    protected $table = 'person';
    protected $primaryKey = 'person_id';
    public $timestamps = false;

    protected $fillable = ['full_name', 'contact_details'];

    public function account(): HasOne
    {
        return $this->hasOne(User::class, 'person_id', 'person_id');
    }

    public function foundItems(): HasMany
    {
        return $this->hasMany(FoundItem::class, 'finder_id', 'person_id');
    }
}
