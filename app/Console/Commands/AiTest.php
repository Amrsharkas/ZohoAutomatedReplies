<?php

namespace App\Console\Commands;

use App\Services\AiReplyService;
use App\Services\ZohoMailService;
use Illuminate\Console\Command;

class AiTest extends Command
{
    protected $signature = 'ai:test {--messageId=} {--limit=1}';
    protected $description = 'Debug AI prompt by generating a reply for the latest inbox email';

    public function handle(ZohoMailService $zoho, AiReplyService $ai): int
    {
        $accountId = $zoho->getAccountId();
        if (!$accountId) {
            $this->error('Missing Zoho account.');
            return self::FAILURE;
        }
        $inboxId = $zoho->getInboxFolderId($accountId) ?? '2';

        $messageId = $this->option('messageId');
        if (!$messageId) {
            $list = $zoho->listMessages($accountId, $inboxId, (int)$this->option('limit'));
            $messageId = $list[0]['messageId'] ?? null;
        }
        if (!$messageId) {
            $this->error('No inbox message found.');
            return self::FAILURE;
        }
        $msg = $zoho->getMessage($accountId, (string)$messageId) ?? [];
        $incoming = strip_tags((string)($msg['content'] ?? ''));
        $subject = $msg['subject'] ?? '';
        $from = $msg['fromAddress'] ?? '';

        $this->line('Subject: ' . $subject);
        $this->line('From: ' . $from);
        $this->line('Body preview: ' . substr($incoming, 0, 200));

        $reply = $ai->suggestReply($incoming, [], $subject, $from);
        $this->newLine();
        $this->info('AI Suggested Reply:');
        $this->line($reply ?? '[NULL]');

        return self::SUCCESS;
    }
}


