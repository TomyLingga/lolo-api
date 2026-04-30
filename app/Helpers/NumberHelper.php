<?php

if (!function_exists('terbilang')) {
    function terbilang($angka)
    {
        $angka = abs((int) $angka);

        $huruf = [
            "", "satu", "dua", "tiga", "empat", "lima",
            "enam", "tujuh", "delapan", "sembilan",
            "sepuluh", "sebelas"
        ];

        if ($angka < 12) {
            return $huruf[$angka];
        } elseif ($angka < 20) {
            return terbilang($angka - 10) . " belas";
        } elseif ($angka < 100) {
            return terbilang($angka / 10) . " puluh " . terbilang($angka % 10);
        } elseif ($angka < 200) {
            return "seratus " . terbilang($angka - 100);
        } elseif ($angka < 1000) {
            return terbilang($angka / 100) . " ratus " . terbilang($angka % 100);
        } elseif ($angka < 2000) {
            return "seribu " . terbilang($angka - 1000);
        } elseif ($angka < 1000000) {
            return terbilang($angka / 1000) . " ribu " . terbilang($angka % 1000);
        } elseif ($angka < 1000000000) {
            return terbilang($angka / 1000000) . " juta " . terbilang($angka % 1000000);
        } elseif ($angka < 1000000000000) {
            return terbilang($angka / 1000000000) . " miliar " . terbilang($angka % 1000000000);
        } else {
            return "angka terlalu besar";
        }
    }
}