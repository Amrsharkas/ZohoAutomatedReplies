<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiReplyService
{
    public function isEnabled(): bool
    {
        return (bool) config('services.openai.api_key');
    }

    public function suggestReply(string $incomingBody, array $pastReplies = [], ?string $subject = null, ?string $fromAddress = null): ?string
    {
        $apiKey = config('services.openai.api_key');
        if (!$apiKey) {
            return null;
        }

        $model = (string) config('services.openai.model', 'gpt-4o-mini');
        $temperature = (float) config('services.openai.temperature', 0.5);
        $maxTokens = (int) config('services.openai.max_tokens', 500);
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com'), '/');

        $system = "You are an expert email assistant. Output only the email BODY (no subject line) suitable for replying directly.\n" .
            "- Mirror the user's tone and style from past replies.\n" .
            "- Be specific to the incoming message; avoid generic boilerplate.\n" .
            "- Never ask for the email content again.\n" .
            "- Keep under 160 words; use short paragraphs or bullets when helpful.";

        $parts = [];
        if ($subject) {
            $parts[] = "Subject: " . $subject;
        }
        if ($fromAddress) {
            $parts[] = "From: " . $fromAddress;
        }
        $parts[] = "Body:\n" . ($incomingBody !== '' ? $incomingBody : '[No body text found]');

        if (!empty($pastReplies)) {
            $sample = implode("\n---\n", array_slice($pastReplies, 0, 5));
            $parts[] = "My past replies (style guide):\n" . $sample;
        }

        $prompt = implode("\n\n", $parts) . "\n\nDraft the reply body now. Do not include a Subject line. Start directly with the greeting or first sentence.";

        $response = Http::withToken($apiKey)
            ->baseUrl($baseUrl)
            ->post('/v1/chat/completions', [
                'model' => $model,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if (env('OPENAI_DEBUG')) {
            Log::info('AI prompt debug', [
                'model' => $model,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'prompt' => $prompt,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        if (!$response->successful()) {
            return null;
        }
        $content = $response->json('choices.0.message.content');
        $content = is_string($content) ? trim($content) : '';
        // Strip accidental 'Subject:' lines or placeholders
        if (str_starts_with(strtolower($content), 'subject:')) {
            $content = trim(preg_replace('/^subject:.*$/mi', '', $content));
        }
        $content = preg_replace('/\[(your|recipient)\'s?\s+name\]|\[no subject\]/i', '', $content);
        if ($content === '' || strlen($content) < 10) {
            return null;
        }
        return $content;
    }
}


