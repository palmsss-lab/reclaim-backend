<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageLocation extends Model
{
    protected $table = 'storage_location';
    protected $primaryKey = 'location_id';
    public $timestamps = false;

    protected $fillable = ['location_code', 'location_description'];
}