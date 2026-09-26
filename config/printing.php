<?php

return [
    /*
    | QZ Tray signing (silent thermal printing, PLAN §8). Generate a certificate + private
    | key once (QZ Tray → Advanced → Site Manager), install the certificate with QZ Tray on
    | each counter / kitchen PC and point these at the files. Without them QZ Tray asks
    | "Allow?" on every print.
    */
    'qz' => [
        'certificate' => env('QZ_CERTIFICATE'),
        'private_key' => env('QZ_PRIVATE_KEY'),
    ],
];
