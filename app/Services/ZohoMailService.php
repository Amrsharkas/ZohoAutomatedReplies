<?php

namespace App\Services;

use App\Models\ZohoToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZohoMailService
{
    /**
     * Cache of signatures per account id for the current process
     */
    private array $accountIdToSignatures = [];
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
        // Try with explicit format param for richer body
        $resp = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken ' . $token])
            ->get(rtrim($base, '/') . "/accounts/{$accountId}/messages/{$messageId}", [
                'format' => 'html',
            ]);
        return $resp->successful() ? $resp->json('data') : null;
    }

    public function getMessageHeaders(string $accountId, string $folderId, string $messageId): array
    {
        $token = $this->getAccessToken();
        $base = $this->getApiBase();
        $url = rtrim($base, '/') . "/accounts/{$accountId}/folders/{$folderId}/messages/{$messageId}/header";
        $resp = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken ' . $token])
            ->get($url);
        if (!$resp->successful()) {
            Log::error('Zoho get headers failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            return [];
        }
        $data = $resp->json('data') ?? [];
        // Flatten potential formats
        $headers = [];
        if (isset($data['headers']) && is_array($data['headers'])) {
            foreach ($data['headers'] as $h) {
                if (isset($h['name']) && isset($h['value'])) {
                    $headers[$h['name']] = $h['value'];
                }
            }
        } elseif (is_array($data)) {
            foreach ($data as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $headers[$k] = $v;
                }
            }
        }
        return $headers;
    }

    public function createDraftReply(string $accountId, string $referenceMessageId, string $toAddress, string $subject, string $content, ?string $inReplyTo = null, ?string $references = null): bool
    {
        $token = $this->getAccessToken();
        if (!$token) return false;
        $base = $this->getApiBase();

        // Append signature from Zoho if available
        $signature = $this->getDefaultSignatureHtml($accountId);
        if ($signature !== '') {
            $content = $this->appendSignature($content, $signature);
        }

        $sanitizedTo = $this->extractEmails($toAddress);
        $from = env('ZOHO_FROM_ADDRESS');
        $messageData = [
            'subject' => $subject,
            'content' => $content,
            'mode' => 'draft',
            'toAddress' => $sanitizedTo,
        ];
        // If content contains HTML, tell Zoho to render it as HTML
        if (strpos($content, '<') !== false) {
            $messageData['contentType'] = 'html';
        }
        if (!empty($from)) {
            $messageData['fromAddress'] = $from;
        }
        if (!empty($inReplyTo)) {
            $messageData['inReplyTo'] = $inReplyTo;
        }
        if (!empty($references)) {
            $messageData['references'] = $references;
        }

        // Create a draft reply using JSON body with inReplyTo/references
        $endpoint = rtrim($base, '/') . "/accounts/{$accountId}/messages";

        $resp = Http::withHeaders([
                'Authorization' => 'Zoho-oauthtoken ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($endpoint, $messageData);

        if (!$resp->successful()) {
            Log::error('Zoho create draft failed', ['status' => $resp->status(), 'body' => $resp->body()]);
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

    private function appendSignature(string $body, string $signature): string
    {
        $isHtml = str_contains($body, '<') || str_contains($signature, '<');
        if ($isHtml) {
            // Both or either side is HTML; append raw HTML signature
            return rtrim($body) . '<br><br>' . $signature;
        }
        return rtrim($body) . "\n\n" . $signature;
    }

    /**
     * Fetch all signatures for the given account from Zoho Mail.
     */
    public function getSignatures(string $accountId): array
    {
        if (isset($this->accountIdToSignatures[$accountId])) {
            return $this->accountIdToSignatures[$accountId];
        }

        $token = $this->getAccessToken();
        if (!$token) return [];
        $base = $this->getApiBase();

        $normalized = [];

        // Try dedicated signatures endpoint
        $resp = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken ' . $token])
            ->get(rtrim($base, '/') . "/accounts/{$accountId}/signatures");
        if ($resp->successful()) {
            $signatures = $resp->json('data') ?? [];
            if (!is_array($signatures)) $signatures = [];
            foreach ($signatures as $sig) {
                if (is_array($sig)) {
                    $normalized[] = [
                        'content' => $sig['content'] ?? ($sig['signature'] ?? ''),
                        'isDefault' => (bool) ($sig['isDefault'] ?? ($sig['default'] ?? false)),
                        'name' => $sig['name'] ?? null,
                    ];
                }
            }
        } else {
            Log::error('Zoho list signatures failed', ['status' => $resp->status(), 'body' => $resp->body()]);
        }

        // Fallback to identities endpoint (often includes signature text)
        if (empty($normalized)) {
            $resp2 = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken ' . $token])
                ->get(rtrim($base, '/') . "/accounts/{$accountId}/identities");
            if ($resp2->successful()) {
                $identities = $resp2->json('data') ?? [];
                if (!is_array($identities)) $identities = [];
                foreach ($identities as $idn) {
                    if (!is_array($idn)) continue;
                    $normalized[] = [
                        'content' => $idn['signature'] ?? ($idn['signatureText'] ?? ''),
                        'isDefault' => (bool) ($idn['isDefault'] ?? ($idn['default'] ?? false)),
                        'name' => $idn['displayName'] ?? ($idn['name'] ?? null),
                    ];
                }
            } else {
                Log::error('Zoho list identities failed', ['status' => $resp2->status(), 'body' => $resp2->body()]);
            }
        }
        $this->accountIdToSignatures[$accountId] = $normalized;
        return $normalized;
    }

    /**
     * Get the default signature HTML/plain text for the account.
     */
    public function getDefaultSignatureHtml(string $accountId): string
    {
        // Highest priority: explicit HTML provided via env
        $html = (string) env('REPLY_SIGNATURE_HTML', '');
        if ($html !== '') {
            return $html;
        }

        $sigs = $this->getSignatures($accountId);
        if (!$sigs) return '';
        $preferredName = trim((string) env('SIGNATURE_NAME', ''));
        if ($preferredName !== '') {
            $match = collect($sigs)->first(function ($s) use ($preferredName) {
                return isset($s['name']) && strcasecmp(trim((string)$s['name']), $preferredName) === 0;
            });
            if ($match) {
                $content = (string) ($match['content'] ?? '');
                return $content;
            }
        }
        $default = collect($sigs)->firstWhere('isDefault', true) ?? $sigs[0];
        $content = (string) ($default['content'] ?? '');
        if ($content !== '') {
            return $content;
        }

        // Final fallback: built-in hardcoded signature
        return $this->hardcodedSignatureHtml();
    }

    /**
     * Hardcoded default signature HTML (project-level constant alternative).
     * Replace this content if you want to change the default without using env.
     */
    private function hardcodedSignatureHtml(): string
    {
        return <<<'HTML'
<p style="font-style:normal; font-weight:400; letter-spacing:normal; orphans:2; text-indent:0px; text-transform:none; white-space:normal; widows:2; word-spacing:0px; margin:0px" class="">
    <span class="highlight" style="background-color:rgb(255, 255, 255)">
        <span class="font" style="font-family:Verdana, Arial, Helvetica, sans-serif">
            <span class="size" style="font-size: 13.3333px; font-style: normal; font-weight: 400; letter-spacing: normal; orphans: 2; text-indent: 0px; text-transform: none; white-space: normal; widows: 2; word-spacing: 0px; margin: 0px;">
                <a target="_blank" name="_MailAutoSig">
                    <i>
                        <span class="size" style="font-size:16px">
                            <span class="colour" style="color:rgb(0, 0, 153)">
                                Best Regards,
                            </span>
                        </span>
                    </i>
                </a>
            </span>
        </span>
    </span>
    <span class="size" style="font-size:16px">
        <span class="colour" style="color:rgb(0, 0, 153)">
            <br>
        </span>
    </span>
</p>
<p style="font-style:normal; font-weight:400; letter-spacing:normal; orphans:2; text-indent:0px; text-transform:none; white-space:normal; widows:2; word-spacing:0px; margin:0px" class="">
    <span class="highlight" style="background-color:rgb(255, 255, 255)">
        <span class="font" style="font-family:Verdana, Arial, Helvetica, sans-serif">
            <span class="size" style="font-size: 13.3333px; font-style: normal; font-weight: 400; letter-spacing: normal; orphans: 2; text-indent: 0px; text-transform: none; white-space: normal; widows: 2; word-spacing: 0px; margin: 0px;">
                <span>
                    <i>
                        <span class="size" style="font-size:16px">
                            <span class="colour" style="color:rgb(0, 0, 153)">
                                &nbsp;
                            </span>
                        </span>
                    </i>
                </span>
            </span>
        </span>
    </span>
    <span class="size" style="font-size:16px">
        <span class="colour" style="color:rgb(0, 0, 153)">
            <br>
        </span>
    </span>
</p>
<p style="font-style:normal; font-weight:400; letter-spacing:normal; orphans:2; text-indent:0px; text-transform:none; white-space:normal; widows:2; word-spacing:0px; margin:0px" class="">
    <span class="highlight" style="background-color:rgb(255, 255, 255)">
        <span class="font" style="font-family:Verdana, Arial, Helvetica, sans-serif">
            <span class="size" style="font-size: 13.3333px; font-style: normal; font-weight: 400; letter-spacing: normal; orphans: 2; text-indent: 0px; text-transform: none; white-space: normal; widows: 2; word-spacing: 0px; margin: 0px;">
                <i>
                    <span class="size" style="font-size:16px">
                        <span class="colour" style="color:rgb(0, 0, 153)">
                            Ashraf Abousaba
                        </span>
                    </span>
                </i>
            </span>
        </span>
    </span>
    <span class="size" style="font-size:16px">
        <span class="colour" style="color:rgb(0, 0, 153)">
            <br>
        </span>
    </span>
</p>
<div>
    <i>
        <span class="size" style="font-size:16px">
            <span class="colour" style="color:rgb(0, 0, 153)">
                Managing Director,
            </span>
        </span>
    </i>
    <br>
</div>
<div>
    <i>
        <span class="highlight" style="background-color:rgb(255, 255, 255)">
            <span class="font" style="font-family:Verdana, Arial, Helvetica, sans-serif">
                <span class="size" style="font-size: 13.3333px; font-style: normal; font-weight: 400; letter-spacing: normal; orphans: 2; text-indent: 0px; text-transform: none; white-space: normal; widows: 2; word-spacing: 0px; margin: 0px;">
                    <i>
                        <span class="size" style="font-size: 10.6667px; width: 400px; height: 225px;">
                            <i>
                                <span class="highlight" style="background-color:rgb(255, 255, 255)">
                                    <span class="font" style="font-family:Verdana, Arial, Helvetica, sans-serif">
                                        <span class="size" style="font-size: 13.3333px; font-style: normal; font-weight: 400; letter-spacing: normal; orphans: 2; text-indent: 0px; text-transform: none; white-space: normal; widows: 2; word-spacing: 0px; margin: 0px;">
                                            <i>
                                                <span class="size" style="font-size:10.6667px">
                                                    <b>
                                                        <span class="colour" style="color:rgb(0, 0, 153)">
                                                            MSc Project Management, PMP, MCIOB
                                                        </span>
                                                    </b>
                                                </span>
                                            </i>
                                        </span>
                                    </span>
                                </span>
                            </i>
                        </span>
                    </i>
                </span>
            </span>
        </span>
    </i>
    <br>
</div>
<div>
    <span class="colour" style="color:rgb(0, 32, 96)">
        <i>
            <span class="highlight" style="background-color:rgb(255, 255, 255)">
                <span class="colour" style="color:rgb(0, 0, 0)">
                    <span class="font" style="font-family:Verdana, Arial, Helvetica, sans-serif">
                        <span class="size" style="font-size: 13.3333px; font-style: normal; font-weight: 400; letter-spacing: normal; orphans: 2; text-indent: 0px; text-transform: none; white-space: normal; widows: 2; word-spacing: 0px; margin: 0px;">
                            <span class="colour" style="color:rgb(0, 32, 96)">
                                <i>
                                    <span class="size" style="font-size: 10.6667px; width: 400px; height: 170px;">
                                        &nbsp;&nbsp;
                                    </span>
                                </i>
                            </span>
                        </span>
                    </span>
                </span>
            </span>
        </i>
    </span>
    <br>
</div>
<div>
    <span class="colour" style="color:rgb(0, 32, 96)">
        <i>
            <span class="highlight" style="background-color:rgb(255, 255, 255)">
                <span class="colour" style="color:rgb(0, 0, 0)">
                    <span class="font" style="font-family:Verdana, Arial, Helvetica, sans-serif">
                        <span class="size" style="font-size: 13.3333px; font-style: normal; font-weight: 400; letter-spacing: normal; orphans: 2; text-indent: 0px; text-transform: none; white-space: normal; widows: 2; word-spacing: 0px; margin: 0px;">
                            <span class="colour" style="color:rgb(0, 32, 96)">
                                <i>
                                    <span class="size" style="font-size: 10.6667px; width: 400px; height: 170px;">
                                        ​
                                    </span>
                                </i>
                            </span>
                        </span>
                    </span>
                </span>
            </span>
        </i>
    </span>
    <img style="cursor:pointer" height="58" width="423" src="/zm/ImageSignature?fileName=1681716534342004_1321676314.jpg&amp;accountId=3719797000000002002&amp;storeName=20087689779&amp;frm=s">
    <br>
</div>
<div style="color:rgb(0, 0, 0); font-family:Verdana, Arial, Helvetica, sans-serif; font-size:13.3333px; font-style:normal; font-weight:400; letter-spacing:normal; orphans:2; text-indent:0px; text-transform:none; white-space:normal; widows:2; word-spacing:0px; background-color:rgb(255, 255, 255)">
    <b>
        <span class="colour" style="color:rgb(0, 32, 96)">
            <span class="size" style="font-size:12pt">
                XYZ Interiors LLC
            </span>
            .
        </span>
    </b>
    <br>
</div>
<div style="color:rgb(0, 0, 0); font-family:Verdana, Arial, Helvetica, sans-serif; font-size:13.3333px; font-style:normal; font-weight:400; letter-spacing:normal; orphans:2; text-indent:0px; text-transform:none; white-space:normal; widows:2; word-spacing:0px; background-color:rgb(255, 255, 255)">
    <span class="colour" style="color:rgb(0, 32, 96)">
        Tel:&nbsp; &nbsp;+971 2 621 1130
    </span>
    <br>
</div>
<p style="font-style:normal; font-weight:400; letter-spacing:normal; orphans:2; text-indent:0px; text-transform:none; white-space:normal; widows:2; word-spacing:0px; margin:0px" class="">
    <span class="highlight" style="background-color:rgb(255, 255, 255)">
        <span class="colour" style="color:rgb(0, 0, 0)">
            <span class="font" style="font-family:Verdana, Arial, Helvetica, sans-serif">
                <span class="size" style="font-size: 13.3333px; font-style: normal; font-weight: 400; letter-spacing: normal; orphans: 2; text-indent: 0px; text-transform: none; white-space: normal; widows: 2; word-spacing: 0px; margin: 0px;">
                    <span class="colour" style="color:rgb(0, 32, 96)">
                        Fax:&nbsp; +971 2 621 1160
                    </span>
                </span>
            </span>
        </span>
    </span>
    <br>
</p>
<p style="font-style:normal; font-weight:400; letter-spacing:normal; orphans:2; text-indent:0px; text-transform:none; white-space:normal; widows:2; word-spacing:0px; margin:0px" class="">
    <span class="highlight" style="background-color:rgb(255, 255, 255)">
        <span class="colour" style="color:rgb(0, 0, 0)">
            <span class="font" style="font-family:Verdana, Arial, Helvetica, sans-serif">
                <span class="size" style="font-size: 13.3333px; font-style: normal; font-weight: 400; letter-spacing: normal; orphans: 2; text-indent: 0px; text-transform: none; white-space: normal; widows: 2; word-spacing: 0px; margin: 0px;">
                    <span class="colour" style="color:rgb(0, 32, 96)">
                        E:
                        <span>
                            &nbsp;
                        </span>
                        <u>
                            <a target="_blank" href="mailto:guillermo@xyz-interiors.com">
                                a
                            </a>
                            <a target="_blank" href="mailto:shraf@xyz-interiors.com">
                                shraf@xyz-interiors.com
                            </a>
                        </u>
                    </span>
                </span>
            </span>
        </span>
    </span>
    <br>
</p>
<p style="font-style:normal; font-weight:400; letter-spacing:normal; orphans:2; text-indent:0px; text-transform:none; white-space:normal; widows:2; word-spacing:0px; margin:0px" class="">
    <span class="highlight" style="background-color:rgb(255, 255, 255)">
        <span class="colour" style="color:rgb(0, 0, 0)">
            <span class="font" style="font-family:Verdana, Arial, Helvetica, sans-serif">
                <span class="size" style="font-size: 13.3333px; font-style: normal; font-weight: 400; letter-spacing: normal; orphans: 2; text-indent: 0px; text-transform: none; white-space: normal; widows: 2; word-spacing: 0px; margin: 0px;">
                    <span class="colour" style="color:rgb(0, 32, 96)">
                        W:
                        <span>
                            &nbsp;
                        </span>
                    </span>
                    <a target="_blank" href="http://www.xyz-interiors.com/">
                        <span>
                            www.xyz-interiors.com
                        </span>
                    </a>
                </span>
            </span>
        </span>
    </span>
    <br>
</p>
<p style="font-style:normal; font-weight:400; letter-spacing:normal; orphans:2; text-indent:0px; text-transform:none; white-space:normal; widows:2; word-spacing:0px; margin:0px" class="">
    <span class="highlight" style="background-color:rgb(255, 255, 255)">
        <span class="colour" style="color:rgb(0, 0, 0)">
            <span class="font" style="font-family:Verdana, Arial, Helvetica, sans-serif">
                <span class="size" style="font-size: 13.3333px; font-style: normal; font-weight: 400; letter-spacing: normal; orphans: 2; text-indent: 0px; text-transform: none; white-space: normal; widows: 2; word-spacing: 0px; margin: 0px;">
                    <span class="colour" style="color:rgb(0, 176, 80)">
                        <img style="cursor:pointer" height="22" width="17" src="/zm/ImageSignature?fileName=1681716534359005_1321676314.jpg&amp;accountId=3719797000000002002&amp;storeName=20087689779&amp;frm=s">
                            &nbsp;Please consider the environment before printing this e-mail
                    </span>
                    <span class="colour" style="color:rgb(0, 32, 96)">
                        . This email and any files transmitted with it are confidential and intended solely for the use of the individual or entity to which they are addressed. If you have received this email in error please reply to this email and then delete it. Any views or opinions presented in this email are solely those of the author and do not necessarily represent those of XYZ Interiors LLC.'
                    </span>
                </span>
            </span>
        </span>
    </span>
    <br>
</p>
<div>
    <br>
</div>
HTML;
    }

    public function extractBodyFromArray(array $data): ?string
    {
        $candidates = [
            'content', 'plainContent', 'plainText', 'text', 'body', 'summary', 'snippet', 'message', 'description',
        ];
        foreach ($candidates as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                return (string) $data[$key];
            }
        }
        // Some APIs nest body under data[0]['content'] structure
        foreach ($data as $value) {
            if (is_array($value)) {
                $inner = $this->extractBodyFromArray($value);
                if ($inner) return $inner;
            }
        }
        return null;
    }

    public function getMessageBody(string $accountId, string $messageId, ?array $listMsg = null): string
    {
        // Prefer body from list message if present
        if ($listMsg) {
            $fromList = $this->extractBodyFromArray($listMsg);
            if (is_string($fromList) && $fromList !== '') {
                return (string) $fromList;
            }
        }

        $full = $this->getMessage($accountId, $messageId) ?? [];
        $fromFull = $this->extractBodyFromArray($full);
        return (string) ($fromFull ?? '');
    }
}


