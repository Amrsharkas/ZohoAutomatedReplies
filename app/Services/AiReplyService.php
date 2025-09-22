<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AiReplyService
{
    public function isEnabled(): bool
    {
        return (bool) config('services.openai.api_key');
    }

    public function suggestReply(string $incomingBody, array $pastReplies = []): ?string
    {
        $apiKey = config('services.openai.api_key');
        if (!$apiKey) {
            return null;
        }

        $model = (string) config('services.openai.model', 'gpt-4o-mini');
        $temperature = (float) config('services.openai.temperature', 0.3);
        $maxTokens = (int) config('services.openai.max_tokens', 500);
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com'), '/');

        $system = 'You are an assistant that writes concise, professional email replies. Match the user\'s prior tone and policies. Do not invent facts.';

        $prompt = "Incoming email:\n" . $incomingBody . "\n\n";
        if (!empty($pastReplies)) {
            $sample = implode("\n---\n", array_slice($pastReplies, 0, 5));
            $prompt .= "Here are some of my past replies. Mirror their style where appropriate:\n" . $sample . "\n\n";
        }
        $prompt .= "Write a suggested reply. Keep it under 180 words.\n";

        // OpenAI Chat Completions
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

        if (!$response->successful()) {
            return null;
        }
        $content = $response->json('choices.0.message.content');
        return is_string($content) ? trim($content) : null;
    }
}


