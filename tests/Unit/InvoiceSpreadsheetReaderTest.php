<?php

use App\Services\InvoiceSpreadsheetReader;

test('it normalizes accounting-formatted numeric cells', function () {
    expect(InvoiceSpreadsheetReader::numericValue('2,400.00'))->toBe('2400.00')
        ->and(InvoiceSpreadsheetReader::numericValue('(1,250.50)'))->toBe('-1250.50')
        ->and(InvoiceSpreadsheetReader::numericValue('$ 1,000.00'))->toBe('1000.00')
        ->and(InvoiceSpreadsheetReader::numericValue(''))->toBeNull()
        ->and(InvoiceSpreadsheetReader::numericValue('not an amount'))->toBeNull();
});
