<?php

namespace App\Console\Commands;

use App\Services\ZohoMailService;
use Illuminate\Console\Command;

class ZohoDebug extends Command
{
    protected $signature = 'zoho:debug {--limit=5}';
    protected $description = 'Print Zoho account, folders, and sample messages for debugging';

    public function handle(ZohoMailService $zoho): int
    {
        $accountId = $zoho->getAccountId();
        $this->info('Account ID: ' . ($accountId ?: 'null'));

        $ref = new \ReflectionClass($zoho);
        $m = $ref->getMethod('getApiBase');
        $m->setAccessible(true);
        $base = $m->invoke($zoho);
        $this->info('API Base: ' . $base);

        if (!$accountId) {
            $this->error('No account ID. Ensure scopes and token are granted.');
            return self::FAILURE;
        }

        $folders = $zoho->listFolders($accountId);
        $this->line('Folders:');
        foreach (array_slice($folders, 0, 20) as $f) {
            $this->line('- ' . (($f['folderId'] ?? '') . ' | ' . ($f['name'] ?? ($f['folderName'] ?? ''))));
        }

        $inboxId = $zoho->getInboxFolderId($accountId) ?? '2';
        $sentId = $zoho->getSentFolderId($accountId) ?? '5';
        $this->info('Resolved Inbox: ' . $inboxId . ' | Sent: ' . $sentId);

        $limit = (int) $this->option('limit');
        $inbox = $zoho->listMessages($accountId, (string)$inboxId, $limit);
        $sent = $zoho->listMessages($accountId, (string)$sentId, $limit);

        $this->info('Inbox count: ' . count($inbox));
        if (!empty($inbox)) {
            $first = $inbox[0];
            $this->line('Sample inbox message: ' . json_encode([
                'messageId' => $first['messageId'] ?? null,
                'from' => $first['fromAddress'] ?? null,
                'subject' => $first['subject'] ?? null,
            ]));
        }

        $this->info('Sent count: ' . count($sent));
        if (!empty($sent)) {
            $first = $sent[0];
            $this->line('Sample sent message: ' . json_encode([
                'messageId' => $first['messageId'] ?? null,
                'to' => $first['toAddress'] ?? null,
                'subject' => $first['subject'] ?? null,
            ]));
        }

        return self::SUCCESS;
    }
}


