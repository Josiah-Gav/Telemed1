<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Consultation extends Model
{
    use HasFactory;

    // 1. Point explicitly to the table shown in your database
    protected $table = 'consultation_requests';

    // 2. Point explicitly to your custom auto-incrementing primary key
    protected $primaryKey = 'request_id';

    // 3. Map your custom timestamp column names from the schema
    const CREATED_AT = 'submitted_at';
    const UPDATED_AT = 'updated_at';

    // 4. Define fields allowed for mass assignment via the Controller's create() method
    protected $fillable = [
        'patient_id',
        'assigned_physician_id',
        'assigned_nurse_id',
        'concern_category',
        'symptoms_desc',
        'online_reason',
        'request_status',
        'file_attachments', // Added your new column here
        'rejection_reason',
    ];

    /**
     * Optional Attribute Casting
     * Automatically json_decodes the text column back into an array when read,
     * and json_encodes it when saved to the database.
     */
    protected $casts = [
        'symptoms_desc'    => 'array',
        'file_attachments' => 'array', // Automatically handles converting arrays to JSON and vice-versa
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id', 'user_id');
    }

    public function nurse()
    {
        return $this->belongsTo(User::class, 'assigned_nurse_id', 'user_id');
    }
}