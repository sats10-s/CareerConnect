<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A single institution setting row - read and written only through App\Services\SettingsService. */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];
}
