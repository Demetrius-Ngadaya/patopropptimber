<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;

/**
 * Reusable column groups for migrations.
 */
final class SchemaColumns
{
    /** created_by / updated_by / deleted_by user references. */
    public static function userstamps(Blueprint $table): void
    {
        $table->foreignId('created_by')->nullable()->index();
        $table->foreignId('updated_by')->nullable();
        $table->foreignId('deleted_by')->nullable();
    }

    /** Standard money column (TZS and most currencies need 2 decimals). */
    public static function money(Blueprint $table, string $name, bool $nullable = false): ColumnDefinition
    {
        $column = $table->decimal($name, 15, 2);

        return $nullable ? $column->nullable() : $column->default(0);
    }

    /** Quantity column: 3 decimals so butchers can sell 0.250 kg. */
    public static function quantity(Blueprint $table, string $name, bool $nullable = false): ColumnDefinition
    {
        $column = $table->decimal($name, 15, 3);

        return $nullable ? $column->nullable() : $column->default(0);
    }
}
