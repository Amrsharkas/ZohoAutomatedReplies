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

        if ($token && $token->expires_at && $token->expires_at->isFuture()) {
            return $token->access_token;
        }

        if ($token && $token->refresh_token) {
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
        $config = config('services.zoho');
        $resp = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken ' . $token])
            ->get(rtrim($config['base_api'], '/') . '/accounts');
        if (!$resp->successful()) {
            Log::error('Zoho list accounts failed', ['body' => $resp->body()]);
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
        $config = config('services.zoho');
        $resp = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken ' . $token])
            ->get(rtrim($config['base_api'], '/') . "/accounts/{$accountId}/folders");
        return $resp->json('data') ?? [];
    }

    public function getFolderIdByName(string $accountId, string $name): ?string
    {
        $folders = $this->listFolders($accountId);
        $found = collect($folders)->first(function ($f) use ($name) {
            return isset($f['name']) && strcasecmp($f['name'], $name) === 0;
        });
        return $found['folderId'] ?? null;
    }

    public function listMessages(string $accountId, string $folderId, int $limit = 10): array
    {
        $token = $this->getAccessToken();
        $config = config('services.zoho');
        $resp = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken ' . $token])
            ->get(rtrim($config['base_api'], '/') . "/accounts/{$accountId}/messages/view", [
            'folderId' => $folderId,
            'limit' => $limit,
        ]);
        return $resp->json('data') ?? [];
    }

    public function getMessage(string $accountId, string $messageId): ?array
    {
        $token = $this->getAccessToken();
        $config = config('services.zoho');
        $resp = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken ' . $token])
            ->get(rtrim($config['base_api'], '/') . "/accounts/{$accountId}/messages/{$messageId}");
        return $resp->successful() ? $resp->json('data') : null;
    }

    public function createDraftReply(string $accountId, string $referenceMessageId, string $toAddress, string $subject, string $content): bool
    {
        $token = $this->getAccessToken();
        if (!$token) return false;
        $config = config('services.zoho');

        $payload = [
            'mode' => 'draft',
            'action' => 'reply',
            'referenceId' => $referenceMessageId,
            'toAddress' => $toAddress,
            'subject' => $subject,
            'content' => $content,
            // Best-effort threading headers; some APIs accept these in payload
            'inReplyTo' => $referenceMessageId,
            'references' => $referenceMessageId,
        ];

        $resp = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken ' . $token])
            ->asForm()
            ->post(rtrim($config['base_api'], '/') . "/accounts/{$accountId}/messages", $payload);

        if (!$resp->successful()) {
            Log::error('Zoho create draft failed', ['body' => $resp->body()]);
            return false;
        }
        return true;
    }
}


