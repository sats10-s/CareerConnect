<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One spelling of a department as it appears in the college's student
 * file. Lookups go through resolve(), which also accepts a department's own
 * code or name, so an alias row is only needed for spellings that are
 * neither.
 */
class DepartmentAlias extends Model
{
    protected $fillable = ['alias', 'department_id'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** "AI & ML", "ai&ml", "AI-ML." all normalize to "AI&ML"-style keys: uppercase, no spaces/dots/hyphens. */
    public static function normalize(string $value): string
    {
        return (string) preg_replace('/[\s.\-_]+/u', '', mb_strtoupper(trim($value)));
    }

    public static function resolve(?string $value): ?Department
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $key = self::normalize($value);
        $alias = self::query()->where('alias', $key)->with('department')->first();

        if ($alias?->department) {
            return $alias->department;
        }

        return Department::query()->get()->first(
            fn (Department $department) => self::normalize($department->code) === $key || self::normalize($department->name) === $key,
        );
    }
}
