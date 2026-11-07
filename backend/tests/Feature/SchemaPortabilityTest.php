<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests run on SQLite, production runs on MySQL / MariaDB. MySQL rejects
 * identifiers longer than 64 characters, so check every index name here.
 */
class SchemaPortabilityTest extends TestCase
{
    public function test_every_index_name_fits_mysql_identifier_limit(): void
    {
        $tooLong = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                if (strlen($index['name']) > 64) {
                    $tooLong[] = "{$table}: {$index['name']} (".strlen($index['name']).')';
                }
            }
        }

        $this->assertSame([], $tooLong, "Give these indexes a shorter explicit name:\n".implode("\n", $tooLong));
    }
}
