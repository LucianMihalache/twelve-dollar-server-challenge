<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    // The schema has created_at only, filled in by the database; Eloquent is not to manage timestamps.
    public $timestamps = false;

    protected $fillable = ['username'];
}
