<?php

namespace App\Http\Controllers;

use App\Models\ZohoToken;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZohoOAuthController extends Controller
{
    public function connect(): RedirectResponse
    {
        $config = config('services.zoho');
        $params = http_build_query([
            'scope' => $config['scope'],
            'client_id' => $config['client_id'],
            'response_type' => 'code',
            'access_type' => 'offline',
            'redirect_uri' => $config['redirect'],
            'prompt' => 'consent',
        ]);
        $url = rtrim($config['base_accounts'], '/') . '/oauth/v2/auth?' . $params;
        return redirect()->away($url);
    }

    public function callback(Request $request)
    {
        $code = $request->get('code');
        abort_if(!$code, 400, 'Missing code');

        $config = config('services.zoho');
        $response = Http::asForm()->post(rtrim($config['base_accounts'], '/') . '/oauth/v2/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $config['redirect'],
            'code' => $code,
        ]);

        if (!$response->successful()) {
            Log::error('Zoho token exchange failed', ['body' => $response->body()]);
            abort(500, 'Token exchange failed');
        }

        // Some Zoho endpoints can return urlencoded response; attempt JSON first, then parse_str
        $data = $response->json();
        if (!is_array($data) || empty($data)) {
            $raw = (string) $response->body();
            $parsed = [];
            parse_str($raw, $parsed);
            if (is_array($parsed) && !empty($parsed)) {
                $data = $parsed;
            }
        }

        if (empty($data['access_token'])) {
            Log::error('Zoho token exchange returned no access_token', ['body' => $response->body()]);
            abort(500, 'Token exchange returned no access token');
        }

        $expiresIn = (int) ($data['expires_in'] ?? 3600);

        ZohoToken::query()->delete();
        ZohoToken::create([
            'access_token' => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'token_type' => $data['token_type'] ?? null,
            'api_domain' => $data['api_domain'] ?? null,
            'expires_at' => Carbon::now()->addSeconds(max(60, $expiresIn - 60)),
        ]);

        return redirect('/')->with('status', 'Zoho connected');
    }
}


