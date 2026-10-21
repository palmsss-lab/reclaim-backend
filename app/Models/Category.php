<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $table = 'category';
    protected $primaryKey = 'category_id';
    public $timestamps = false;

    protected $fillable = ['category_name'];

    public function items(): HasMany
    {
        return $this->hasMany(FoundItem::class, 'category_id', 'category_id');
    }
}