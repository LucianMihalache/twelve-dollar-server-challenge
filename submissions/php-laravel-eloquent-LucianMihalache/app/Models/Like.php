<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Like extends Model
{
    public $timestamps = false;

    // A like is (user_id, post_id): there is no id column to count up.
    public $incrementing = false;

    protected $fillable = ['user_id', 'post_id'];
}
