<?php

use Foysal50x\Tashil\Services\Generators\InvoiceNumberGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

it('generates invoice number inv yymmdd nnnnnn', function () {
    Config::set('tashil.invoice.prefix', 'INV');
    Config::set('tashil.invoice.format', '#-YYMMDD-NNNNNN');
    
    Carbon::setTestNow('2023-11-25'); // YY=23, MM=11, DD=25

    $generator = new InvoiceNumberGenerator();
    $number = $generator->generate();

    // Expect: INV-231125-xxxxxx
    expect($number)->toStartWith('INV-231125-')
        ->and(strlen($number))->toBe(17)
        ->and($number)->toMatch('/^INV-231125-[0-9]{6}$/');
});

it('supports custom format with letters and alphanumeric', function () {
    Config::set('tashil.invoice.prefix', 'TSH');
    // Format: #/YY/S/A/N
    Config::set('tashil.invoice.format', '#/YY/S/A/N');
    
    Carbon::setTestNow('2024-05-10');

    $generator = new InvoiceNumberGenerator();
    $number = $generator->generate();

    // Expect: TSH/24/X/X/9
    $parts = explode('/', $number);
    
    expect($parts[0])->toBe('TSH')
        ->and($parts[1])->toBe('24')
        // S = Letter
        ->and($parts[2])->toMatch('/^[A-Z]$/')
        // A = Alphanumeric
        ->and($parts[3])->toMatch('/^[A-Z0-9]$/')
        // N = Digit
        ->and($parts[4])->toMatch('/^[0-9]$/');
});

it('handles prefix inside random generation correctly', function () {
    Config::set('tashil.invoice.prefix', 'NAN'); // Contains N and A
    Config::set('tashil.invoice.format', '#-NN'); 

    $generator = new InvoiceNumberGenerator();
    $number = $generator->generate();
    
    expect($number)->toStartWith('NAN-')
        ->and($number)->toMatch('/^NAN-[0-9]{2}$/');
});
