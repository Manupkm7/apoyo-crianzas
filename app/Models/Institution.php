<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Institution extends Model
{
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'address',
        'phone',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'type', 'is_active'])->logOnlyDirty();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function medicalCheckups(): HasMany
    {
        return $this->hasMany(MedicalCheckup::class);
    }

    public function vaccinations(): HasMany
    {
        return $this->hasMany(Vaccination::class);
    }

    public function educationRecords(): HasMany
    {
        return $this->hasMany(EducationRecord::class);
    }

    public function socialRecords(): HasMany
    {
        return $this->hasMany(SocialRecord::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }
}
