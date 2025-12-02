<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificationRequest extends Model
{
    protected $fillable = [
      'user_id', 
      'plan_type', 
      'payment_proof', 
      'transaction_id',
      'status',
      'admin_notes',
      'reviewed_at',
    ]; 
}
