<?php

namespace App\Console\Commands;

use App\Services\SuggestionEngine;
use App\Services\AiReplyService;
use App\Services\ZohoMailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateReplyDrafts extends Command
{
    protected $signature = 'zoho:generate-drafts {--limit=10} {--reprocess}';
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
            if ($already && !$this->option('reprocess')) {
                $this->line("Skipping already processed message {$messageId}");
                continue;
            }

            $incomingBody = $zoho->getMessageBody($accountId, (string)$messageId, $msg ?? null);
            $incomingBody = strip_tags((string)$incomingBody);

            // Build headers for threading
            $headers = $zoho->getMessageHeaders($accountId, (string)$inboxId, (string)$messageId);
            $inReplyTo = $headers['Message-Id'] ?? $headers['Message-ID'] ?? null;
            $references = $headers['References'] ?? $inReplyTo;
            $toAddress = $msg['fromAddress'] ?? null;
            $subject = $msg['subject'] ?? 'Re:';

            $suggested = null;
            if ($ai->isEnabled()) {
                $suggested = $ai->suggestReply($incomingBody, $pastBodies, $subject, $toAddress);
            }
            // Fallback to similarity if AI is empty/too short
            if (!$suggested || strlen(trim($suggested)) < 10) {
                $suggested = $engine->suggestReply($incomingBody, $pastBodies);
            }

            if (env('OPENAI_DEBUG')) {
                $this->line('Suggested reply preview: ' . substr((string)$suggested, 0, 160));
            }

            if ($suggested && $toAddress) {
                $ok = $zoho->createDraftReply($accountId, (string)$messageId, (string)$toAddress, (string)$subject, (string)$suggested, $inReplyTo, $references);
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


