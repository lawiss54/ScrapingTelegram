<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
      'user_id', 
      'plan_type', 
      'price', 
      'starts_at',
      'ends_at',
      'is_active',
      'status',
    ]; 
    
    
}
