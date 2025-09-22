<?php

namespace App\Services;

use App\Models\ZohoToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZohoMailService
{
    public function getAccessToken(): ?string
    {
        $token = ZohoToken::query()->latest('id')->first();
        $config = config('services.zoho');

        if ($token && $token->access_token && $token->expires_at && $token->expires_at->isFuture()) {
            Log::info('Zoho token found and valid', ['expires_at' => (string) $token->expires_at]);
            return $token->access_token;
        }

        if ($token && $token->refresh_token) {
            Log::info('Zoho token expired or missing; attempting refresh');
            return $this->refreshAccessToken($token->refresh_token);
        }

        $refresh = env('ZOHO_REFRESH_TOKEN');
        if ($refresh) {
            return $this->refreshAccessToken($refresh);
        }

        return null;
    }

    private function refreshAccessToken(string $refreshToken): ?string
    {
        $config = config('services.zoho');
        $response = Http::asForm()->post(rtrim($config['base_accounts'], '/') . '/oauth/v2/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'refresh_token' => $refreshToken,
        ]);

        if (!$response->successful()) {
            Log::error('Zoho token refresh failed', ['body' => $response->body()]);
            return null;
        }
        $data = $response->json();
        $expiresIn = $data['expires_in'] ?? 3600;

        $record = ZohoToken::query()->latest('id')->first();
        if (!$record) {
            $record = new ZohoToken();
            $record->refresh_token = $refreshToken;
        }
        $record->access_token = $data['access_token'] ?? null;
        $record->token_type = $data['token_type'] ?? null;
        $record->api_domain = $data['api_domain'] ?? null;
        $record->expires_at = Carbon::now()->addSeconds($expiresIn - 60);
        $record->save();

        return $record->access_token;
    }

    public function getAccountId(): ?string
    {
        $fromEnv = env('ZOHO_ACCOUNT_ID');
        if ($fromEnv) {
            return $fromEnv;
        }
        $token = $this->getAccessToken();
        if (!$token) {
            return null;
        }
        $base = $this->getApiBase();
        Log::info('Zoho list accounts using base', ['base' => $base]);
        $resp = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken ' . $token])
            ->get(rtrim($base, '/') . '/accounts');
        if (!$resp->successful()) {
            Log::error('Zoho list accounts failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            return null;
        }
        $accounts = $resp->json('data') ?? [];
        $primary = collect($accounts)->firstWhere('isDefault', true) ?? $accounts[0] ?? null;
        if ($primary && isset($primary['accountId'])) {
            ZohoToken::query()->latest('id')->first()?->update(['account_id' => $primary['accountId']]);
            return $primary['accountId'];
        }
        return null;
    }

    public function listFolders(string $accountId): array
    {
        $token = $this->getAccessToken();
        $base = $this->getApiBase();
        $resp = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken ' . $token])
            ->get(rtrim($base, '/') . "/accounts/{$accountId}/folders");
        return $resp->json('data') ?? [];
    }

    public function getFolderIdByName(string $accountId, string $name): ?string
    {
        $folders = $this->listFolders($accountId);
        $found = collect($folders)->first(function ($f) use ($name) {
            $n = $f['name'] ?? ($f['folderName'] ?? '');
            return is_string($n) && strcasecmp($n, $name) === 0;
        });
        return $found['folderId'] ?? null;
    }

    public function getInboxFolderId(string $accountId): ?string
    {
        $override = env('ZOHO_INBOX_FOLDER_ID');
        if (!empty($override)) {
            return (string) $override;
        }
        $folders = $this->listFolders($accountId);
        $found = collect($folders)->first(function ($f) {
            $n = strtolower((string) ($f['name'] ?? ($f['folderName'] ?? '')));
            return str_contains($n, 'inbox');
        });
        return $found['folderId'] ?? null;
    }

    public function getSentFolderId(string $accountId): ?string
    {
        $override = env('ZOHO_SENT_FOLDER_ID');
        if (!empty($override)) {
            return (string) $override;
        }
        $folders = $this->listFolders($accountId);
        $found = collect($folders)->first(function ($f) {
            $n = strtolower((string) ($f['name'] ?? ($f['folderName'] ?? '')));
            return str_contains($n, 'sent'); // matches "Sent", "Sent Items", "Sent Mail"
        });
        return $found['folderId'] ?? null;
    }

    public function listMessages(string $accountId, string $folderId, int $limit = 10): array
    {
        $token = $this->getAccessToken();
        $base = $this->getApiBase();
        $resp = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken ' . $token])
            ->get(rtrim($base, '/') . "/accounts/{$accountId}/messages/view", [
            'folderId' => $folderId,
            'limit' => $limit,
        ]);
        return $resp->json('data') ?? [];
    }

    public function getMessage(string $accountId, string $messageId): ?array
    {
        $token = $this->getAccessToken();
        $base = $this->getApiBase();
        $resp = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken ' . $token])
            ->get(rtrim($base, '/') . "/accounts/{$accountId}/messages/{$messageId}");
        return $resp->successful() ? $resp->json('data') : null;
    }

    public function createDraftReply(string $accountId, string $referenceMessageId, string $toAddress, string $subject, string $content): bool
    {
        $token = $this->getAccessToken();
        if (!$token) return false;
        $base = $this->getApiBase();

        $sanitizedTo = $this->extractEmails($toAddress);
        $messageData = [
            'toAddress' => $sanitizedTo,
            'subject' => $subject,
            'content' => $content,
            'contentType' => 'html',
        ];
        $from = env('ZOHO_FROM_ADDRESS');
        if (!empty($from)) {
            $messageData['fromAddress'] = $from;
        }

        $query = http_build_query([
            'mode' => 'draft',
            'action' => 'reply',
            'referenceId' => $referenceMessageId,
        ]);

        $resp = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken ' . $token])
            ->asForm()
            ->post(rtrim($base, '/') . "/accounts/{$accountId}/messages?{$query}", [
                'data' => json_encode($messageData),
            ]);

        if (!$resp->successful()) {
            Log::error('Zoho create draft failed', ['body' => $resp->body()]);
            return false;
        }
        return true;
    }

    private function getApiBase(): string
    {
        // If Zoho provided api_domain, use that to infer correct Mail API DC
        $record = ZohoToken::query()->latest('id')->first();
        $apiDomain = (string) optional($record)->api_domain;
        if ($apiDomain !== '') {
            $host = parse_url($apiDomain, PHP_URL_HOST) ?: '';
            if (str_contains($host, 'zohoapis.eu') || str_ends_with($host, 'zoho.eu')) {
                return 'https://mail.zoho.eu/api';
            }
            if (str_contains($host, 'zohoapis.in') || str_ends_with($host, 'zoho.in')) {
                return 'https://mail.zoho.in/api';
            }
            if (str_contains($host, 'zohoapis.com.au') || str_ends_with($host, 'zoho.com.au')) {
                return 'https://mail.zoho.com.au/api';
            }
            if (str_contains($host, 'zohoapis.com.cn') || str_ends_with($host, 'zoho.com.cn')) {
                return 'https://mail.zoho.com.cn/api';
            }
            return 'https://mail.zoho.com/api';
        }

        // Else, use configured base (defaults to US)
        return rtrim((string) config('services.zoho.base_api', 'https://mail.zoho.com/api'), '/');
    }

    private function extractEmails(string $mixed): string
    {
        // Extract plain email addresses and return comma-separated
        preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $mixed, $matches);
        $emails = array_unique($matches[0] ?? []);
        return implode(',', $emails);
    }
}


