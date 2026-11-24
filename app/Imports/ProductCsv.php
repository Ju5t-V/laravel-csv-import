<?php

namespace App\Imports;

use App\Support\PriceParser;
use App\Support\Utf8Sanitizer;
use Generator;

final readonly class ProductCsv
{
    private const COLUMNS = [
        'UNIQUE_KEY' => 'unique_key',
        'PRODUCT_TITLE' => 'product_title',
        'PRODUCT_DESCRIPTION' => 'product_description',
        'STYLE#' => 'style_number',
        'SANMAR_MAINFRAME_COLOR' => 'sanmar_mainframe_color',
        'SIZE' => 'size',
        'COLOR_NAME' => 'color_name',
        'PIECE_PRICE' => 'piece_price',
    ];

    private const SAMPLE_BYTES = 1024;

    /**
     * @param  array<string, int|null>  $positions
     */
    private function __construct(private array $positions) {}

    /**
     * @param  array<int, string|null>  $header
     */
    public static function fromHeader(array $header): ?self
    {
        $positions = array_fill_keys(self::COLUMNS, null);
        foreach ($header as $index => $cell) {
            $name = Utf8Sanitizer::normalizeHeader($cell ?? '');
            if (isset(self::COLUMNS[$name])) {
                $positions[self::COLUMNS[$name]] = $index;
            }
        }

        return $positions['unique_key'] === null ? null : new self($positions);
    }

    /**
     * @param  iterable<int, array<int, string|null>>  $records
     * @return Generator<int, ProductImportChunk>
     */
    public function chunks(iterable $records, int $size, int $uploadId, int $samplesLeft): Generator
    {
        $products = [];
        $errors = [];
        $processed = 0;
        $skipped = 0;

        foreach ($records as $index => $record) {
            $row = $this->map($record);
            $key = $row['unique_key'];
            $price = PriceParser::parse($row['piece_price']);
            $reason = match (true) {
                $key === null => 'Blank UNIQUE_KEY. Row skipped.',
                $price === null && $row['piece_price'] !== null => 'Invalid PIECE_PRICE. Stored as null.',
                default => null,
            };
            if ($samplesLeft > 0 && $reason !== null) {
                $errors[] = [
                    'upload_id' => $uploadId,
                    'line_number' => $index + 1,
                    'reason' => $reason,
                    'raw' => self::sample($row),
                    'created_at' => now(),
                ];
                $samplesLeft--;
            }

            if ($key === null) {
                $skipped++;
            } else {
                $products[$key] = [...$row, 'piece_price' => $price, 'last_upload_id' => $uploadId];
            }

            if (++$processed === $size) {
                yield new ProductImportChunk($processed, $skipped, array_values($products), $errors);
                $products = [];
                $errors = [];
                $processed = 0;
                $skipped = 0;
            }
        }

        if ($processed > 0) {
            yield new ProductImportChunk($processed, $skipped, array_values($products), $errors);
        }
    }

    /**
     * @param  array<int, string|null>  $record
     * @return array<string, string|null>
     */
    private function map(array $record): array
    {
        $row = [];
        foreach ($this->positions as $column => $index) {
            $value = $index === null ? null : Utf8Sanitizer::clean($record[$index] ?? null);
            $row[$column] = $value === '' ? null : $value;
        }

        return $row;
    }

    /**
     * @param  array<string, string|null>  $row
     */
    private static function sample(array $row): string
    {
        // Limit the encoded JSON, keeping field names and whole UTF-8 characters.
        $bytes = self::SAMPLE_BYTES;
        do {
            $sample = array_map(fn (?string $value) => $value === null ? null : mb_strcut($value, 0, $bytes, 'UTF-8'), $row);
            $json = json_encode($sample, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $bytes = intdiv($bytes, 2);
        } while (strlen($json) > self::SAMPLE_BYTES);

        return $json;
    }
}
