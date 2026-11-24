<?php

namespace App\Database;

use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;

/**
 * SQLite connection that stores date bindings with millisecond precision.
 *
 * Laravel binds Carbon values as "Y-m-d H:i:s", which drops the fraction and
 * makes a sub-second import report duration_ms = 0.
 */
class MillisecondSqliteConnection extends SQLiteConnection
{
    protected function getDefaultQueryGrammar()
    {
        return new class($this) extends SQLiteGrammar
        {
            public function getDateFormat()
            {
                return 'Y-m-d H:i:s.v';
            }
        };
    }
}
