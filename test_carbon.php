<?php
require 'vendor/autoload.php';
use Carbon\Carbon;

$s = Carbon::parse('2026-05-01')->startOfDay();
$e = Carbon::parse('2026-05-31')->startOfDay()->addDay(); // 2026-06-01 00:00:00

$m = $s->diffInMonths($e);
echo "DiffInMonths: $m\n";

if ($s->copy()->addMonths($m)->lt($e)) {
    echo "Adding one month because " . $s->copy()->addMonths($m)->toDateTimeString() . " < " . $e->toDateTimeString() . "\n";
    $m++;
}

echo "Final Months: $m\n";
