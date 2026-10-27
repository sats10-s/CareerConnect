<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** Eloquent bulk mutations are disabled for append-only audit models. */
class AppendOnlyBuilder extends Builder
{
    public function update(array $values)
    {
        throw new LogicException('This audit record is append-only and cannot be updated.');
    }

    public function delete()
    {
        throw new LogicException('This audit record is append-only and cannot be deleted.');
    }

    public function forceDelete()
    {
        throw new LogicException('This audit record is append-only and cannot be deleted.');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('This audit record is append-only and cannot be updated.');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('This audit record is append-only and cannot be updated.');
    }
}
