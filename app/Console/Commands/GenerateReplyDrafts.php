<?php

namespace App\Console\Commands;

use App\Services\SuggestionEngine;
use App\Services\AiReplyService;
use App\Services\ZohoMailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateReplyDrafts extends Command
{
    protected $signature = 'zoho:generate-drafts {--limit=10}';
    protected $description = 'Generate reply drafts for recent emails using past replies';

    public function handle(ZohoMailService $zoho, SuggestionEngine $engine, AiReplyService $ai): int
    {
        $accountId = $zoho->getAccountId();
        if (!$accountId) {
            $this->error('Missing Zoho account id or token. Connect first.');
            return self::FAILURE;
        }

        $inboxId = $zoho->getInboxFolderId($accountId) ?? $zoho->getFolderIdByName($accountId, 'Inbox') ?? '2';
        $sentId = $zoho->getSentFolderId($accountId) ?? $zoho->getFolderIdByName($accountId, 'Sent') ?? '5';

        $limit = (int) $this->option('limit');
        $this->info("Using inbox folder: " . ($inboxId ?? 'unknown') . ' | sent folder: ' . ($sentId ?? 'unknown'));
        $recent = $zoho->listMessages($accountId, $inboxId, $limit);
        $this->info('Fetched ' . count($recent) . ' recent inbox emails');

        // Build a simple corpus of past replies from Sent
        $past = $zoho->listMessages($accountId, $sentId, 50);
        $this->info('Fetched ' . count($past) . ' past sent emails');
        $pastBodies = [];
        foreach ($past as $msg) {
            $mid = $msg['messageId'] ?? null;
            $content = $msg['content'] ?? null;
            if (!$content && $mid) {
                $full = $zoho->getMessage($accountId, (string)$mid);
                $content = $full['content'] ?? null;
            }
            if ($content) {
                $pastBodies[] = strip_tags((string)$content);
            }
        }

        foreach ($recent as $msg) {
            $messageId = $msg['messageId'] ?? null;
            if (!$messageId) continue;

            $already = DB::table('zoho_processed_messages')->where('message_id', $messageId)->exists();
            if ($already) continue;

            $incomingContent = $msg['content'] ?? null;
            if (!$incomingContent && $messageId) {
                $full = $zoho->getMessage($accountId, (string)$messageId);
                $incomingContent = $full['content'] ?? '';
            }
            $incomingBody = strip_tags((string)$incomingContent);
            $toAddress = $msg['fromAddress'] ?? null;
            $subject = $msg['subject'] ?? 'Re:';

            $suggested = null;
            if ($ai->isEnabled()) {
                $suggested = $ai->suggestReply($incomingBody, $pastBodies);
            }
            if (!$suggested) {
                $suggested = $engine->suggestReply($incomingBody, $pastBodies);
            }
            if ($suggested && $toAddress) {
                $ok = $zoho->createDraftReply($accountId, (string)$messageId, (string)$toAddress, (string)$subject, (string)$suggested);
                if ($ok) {
                    DB::table('zoho_processed_messages')->insert(['message_id' => $messageId, 'created_at' => now(), 'updated_at' => now()]);
                    $this->info("Draft created for message {$messageId}");
                } else {
                    $this->warn("Failed to create draft for message {$messageId}");
                }
            }
        }

        return self::SUCCESS;
    }
}


