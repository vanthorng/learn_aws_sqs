<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use RuntimeException;
use XMLReader;

class InvoiceSpreadsheetReader
{
    /** @var list<string> */
    public const HEADERS = ['Doc Number', 'Customer', 'Txn Date', 'Due Date', 'Ship Date', 'Ship Method Name', 'Tracking Num', 'Sales Term', 'Location', 'Class', 'Bill Addr Line 1', 'Bill Addr Line 2', 'Bill Addr Line 3', 'Bill Addr Line 4', 'Bill Addr City', 'Bill Addr State', 'Bill Addr Postal Code', 'Bill Addr Country', 'Ship Addr Line 1', 'Ship Addr Line 2', 'Ship Addr Line 3', 'Ship Addr Line 4', 'Ship Addr City', 'Ship Addr State', 'Ship Addr Postal Code', 'Ship Addr Country', 'Private Note', 'Msg', 'Bill Email', 'Bill Email CC', 'Bill Email BCC', 'Currency', 'Exchange Rate', 'Amounts Incl Tax', 'Deposit', 'To Be Printed', 'To Be Emailed', 'Allow IPN Payment', 'Allow Online Credit Card Payment', 'Allow Online ACH Payment', 'Ship Amt', 'Ship Item', 'Ship Tax Code', 'Discount Amt', 'Discount Rate', 'Line Service Date', 'Line Item', 'Line Desc', 'Line Qty', 'Line Unit Price', 'Line Amount', 'Line Class', 'Line Tax Code'];

    /** @var array<int, string>|null */
    private ?array $sharedStrings = null;

    public function __construct(private readonly string $path) {}

    public function assertValidTemplate(): int
    {
        $headerFound = false;
        $count = 0;
        foreach ($this->rows() as $row) {
            if (! $headerFound) {
                $headerFound = true;
                if (array_values($row['values']) !== self::HEADERS) {
                    throw new RuntimeException('The file must contain an Invoice sheet with the supported invoice headers.');
                }
            } elseif ($this->isPopulated($row['values'])) {
                $count++;
            }
        }

        if (! $headerFound) {
            throw new RuntimeException('The file does not contain an Invoice worksheet.');
        }

        return $count;
    }

    /** @return \Generator<int, array{rowNumber: int, values: array<string, string|null>}> */
    public function dataRows(): \Generator
    {
        $rows = $this->rows();
        $headers = null;

        foreach ($rows as $row) {
            if ($headers === null) {
                $headers = array_values($row['values']);
                continue;
            }

            if (! $this->isPopulated($row['values'])) {
                continue;
            }

            yield [
                'rowNumber' => $row['rowNumber'],
                'values' => array_combine($headers, array_values($row['values'])),
            ];
        }
    }

    /** @return \Generator<int, array{rowNumber: int, values: list<string|null>}> */
    private function rows(): \Generator
    {
        $reader = new XMLReader;
        $uri = 'zip://'.$this->path.'#xl/worksheets/sheet1.xml';

        if (! $reader->open($uri)) {
            throw new RuntimeException('Unable to open the Invoice worksheet.');
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $rowNumber = (int) $reader->getAttribute('r');
                $rowXml = $reader->readOuterXml();
                $xml = simplexml_load_string($rowXml);
                $values = array_fill(0, count(self::HEADERS), null);

                foreach ($xml->c as $cell) {
                    $reference = (string) $cell['r'];
                    $column = preg_replace('/\d+/', '', $reference);
                    $index = $this->columnIndex($column);
                    if ($index >= count($values)) {
                        continue;
                    }

                    $raw = (string) $cell->v;
                    $values[$index] = match ((string) $cell['t']) {
                        's' => $this->strings()[(int) $raw] ?? null,
                        'inlineStr' => trim((string) $cell->is->t),
                        default => $raw === '' ? null : trim($raw),
                    };
                }

                yield ['rowNumber' => $rowNumber, 'values' => $values];
            }
        } finally {
            $reader->close();
        }
    }

    /** @return array<int, string> */
    private function strings(): array
    {
        if ($this->sharedStrings !== null) {
            return $this->sharedStrings;
        }

        $this->sharedStrings = [];
        $reader = new XMLReader;
        if (! $reader->open('zip://'.$this->path.'#xl/sharedStrings.xml')) {
            return $this->sharedStrings;
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                    $this->sharedStrings[] = trim(strip_tags($reader->readOuterXml()));
                }
            }
        } finally {
            $reader->close();
        }

        return $this->sharedStrings;
    }

    private function columnIndex(string $column): int
    {
        $index = 0;
        foreach (str_split($column) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /** @param list<string|null> $values */
    private function isPopulated(array $values): bool
    {
        return collect($values)->contains(fn ($value) => $value !== null && $value !== '');
    }

    public static function spreadsheetDate(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Convert an Excel/accounting-formatted numeric value to a database-safe
     * decimal string without losing precision. Returns null for blank/invalid values.
     */
    public static function numericValue(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $normalized = trim(str_replace([',', '$', '€', '£', '៛', "\u{00a0}"], '', $value));

        if (str_starts_with($normalized, '(') && str_ends_with($normalized, ')')) {
            $normalized = '-'.substr($normalized, 1, -1);
        }

        $normalized = rtrim($normalized, '%');

        return is_numeric($normalized) ? $normalized : null;
    }
}
