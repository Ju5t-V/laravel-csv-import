<?php

return [
    'max_kb' => (int) env('IMPORT_MAX_KB', 102400),
    'chunk_size' => (int) env('IMPORT_CHUNK_SIZE', 1000),
];
