<?php

namespace App\Services\Core;

use App\Models\Core\DocumentSequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Collision-free document numbers (INV-2026-000001).
 * A row lock guarantees two tills never receive the same number.
 */
class NumberGenerator
{
    public function next(string $type): string
    {
        $prefix = config("mauzo.prefixes.{$type}", strtoupper(substr($type, 0, 3)));
        $year = (int) now()->format('Y');

        try {
            DocumentSequence::query()->firstOrCreate(['type' => $type, 'year' => $year], ['last_number' => 0]);
        } catch (UniqueConstraintViolationException) {
            // Another request created the row at the same moment - fine.
        }

        $number = DB::transaction(function () use ($type, $year) {
            $sequence = DocumentSequence::query()
                ->where('type', $type)
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();

            $sequence->increment('last_number');

            return (int) $sequence->last_number;
        });

        return sprintf('%s-%d-%06d', $prefix, $year, $number);
    }
}
