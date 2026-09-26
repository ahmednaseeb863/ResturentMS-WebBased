<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Certificate and request signing for QZ Tray, so kitchen / counter PCs print silently
 * (config/printing.php). 404 when not set up — QZ Tray then asks before printing.
 */
class QzController extends Controller
{
    public function certificate(): Response
    {
        $path = config('printing.qz.certificate');
        abort_unless($path && is_readable($path), 404);

        return response(file_get_contents($path), 200, ['Content-Type' => 'text/plain']);
    }

    public function sign(Request $request): Response
    {
        $data = $request->validate(['request' => ['required', 'string', 'max:10000']]);

        $path = config('printing.qz.private_key');
        abort_unless($path && is_readable($path), 404);

        $key = openssl_pkey_get_private(file_get_contents($path));
        abort_unless($key && openssl_sign($data['request'], $signature, $key, OPENSSL_ALGO_SHA512), 500);

        return response(base64_encode($signature), 200, ['Content-Type' => 'text/plain']);
    }
}
