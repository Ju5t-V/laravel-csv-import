<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class GenerateProductsCsv extends Command
{
    protected $signature = 'products:generate-csv
        {rows=10000 : Number of data rows to write}
        {--dirty : Add a BOM, CP1252 bytes, blank and duplicate keys, "$1,234.50" prices and control characters}
        {--pad=0 : Pad each description with this many bytes (to reach a target file size)}';

    protected $description = 'Stream a sample product CSV into storage/app/samples/';

    private const HEADER = [
        'UNIQUE_KEY', 'PRODUCT_TITLE', 'PRODUCT_DESCRIPTION', 'STYLE#',
        'SANMAR_MAINFRAME_COLOR', 'SIZE', 'COLOR_NAME', 'PIECE_PRICE',
    ];

    private const COLORS = ['Navy', 'Black', 'Red', 'Royal', 'Forest', 'Charcoal'];

    private const SIZES = ['S', 'M', 'L', 'XL', '2XL'];

    public function handle(): int
    {
        $rows = (int) $this->argument('rows');
        $pad = (int) $this->option('pad');
        if ($rows < 1 || $pad < 0) {
            $this->error('rows must be at least 1 and --pad must not be negative.');

            return self::FAILURE;
        }

        $dirty = (bool) $this->option('dirty');
        $directory = storage_path('app/samples');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/products-'.$rows.($dirty ? '-dirty' : '').'.csv';

        $this->write($path, $rows, $dirty, $pad);
        $this->info('Wrote '.number_format($rows).' rows ('.number_format((int) filesize($path)).' bytes) to '.$path);

        return self::SUCCESS;
    }

    private function write(string $path, int $rows, bool $dirty, int $pad): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Could not open {$path} for writing.");
        }

        try {
            if ($dirty) {
                fwrite($handle, "\xEF\xBB\xBF");
            }
            fputcsv($handle, self::HEADER, ',', '"', '');
            for ($i = 1; $i <= $rows; $i++) {
                fputcsv($handle, $dirty ? $this->dirtyRow($i, $pad) : $this->cleanRow($i, $pad), ',', '"', '');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return list<string> */
    private function cleanRow(int $i, int $pad): array
    {
        return [
            sprintf('P%07d', $i),
            "Sample Tee {$i}",
            $this->description($i, $pad),
            sprintf('S%05d', $i % 5000),
            strtoupper(self::COLORS[$i % 6]),
            self::SIZES[$i % 5],
            self::COLORS[$i % 6],
            number_format(5 + ($i % 4000) / 100, 2, '.', ''),
        ];
    }

    /**
     * Every row type is chosen by the row number, so the output is deterministic.
     *
     * @return list<string>
     */
    private function dirtyRow(int $i, int $pad): array
    {
        $row = $this->cleanRow($i, $pad);

        if ($i % 97 === 0) {
            $row[0] = '';                                    // blank key
        } elseif ($i % 50 === 0) {
            $row[0] = sprintf('P%07d', $i - 1);              // duplicate of the previous row's key
        }
        if ($i % 7 === 0) {
            $row[1] = "Sample Tee {$i}\xAE";                 // CP1252 single bytes: (R) (TM) e-acute
            $row[2] = "Caf\xE9 cotton\x99 blend. ".$row[2];
        }
        if ($i % 11 === 0) {
            $row[7] = '$'.number_format(1234.5 + $i, 2);     // $1,234.50 style prices
        }
        if ($i % 13 === 0) {
            $row[1] = "Sample\x01 Tee\x07 {$i}";             // control characters
        }
        if ($i % 211 === 0) {
            $row[7] = 'abc';                                 // unparseable price
        }

        return $row;
    }

    private function description(int $i, int $pad): string
    {
        $text = "Soft ringspun cotton tee, style {$i}.";

        return $pad > 0 ? $text.' '.str_repeat('lorem ipsum ', intdiv($pad, 12) + 1) : $text;
    }
}
