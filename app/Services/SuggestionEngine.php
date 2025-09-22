<?php

namespace App\Services;

class SuggestionEngine
{
    public function suggestReply(string $incomingBody, array $pastReplies): ?string
    {
        if (empty($pastReplies)) {
            return null;
        }

        $incomingVector = $this->textToVector($incomingBody);
        $bestScore = -1.0;
        $bestReply = null;
        foreach ($pastReplies as $reply) {
            $vector = $this->textToVector($reply);
            $score = $this->cosineSimilarity($incomingVector, $vector);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestReply = $reply;
            }
        }
        return $bestReply;
    }

    private function textToVector(string $text): array
    {
        $tokens = preg_split('/[^a-z0-9]+/i', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $freq = [];
        foreach ($tokens as $t) {
            if (strlen($t) <= 2) continue;
            $freq[$t] = ($freq[$t] ?? 0) + 1;
        }
        return $freq;
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0; $na = 0; $nb = 0;
        foreach ($a as $k => $va) {
            $dot += $va * ($b[$k] ?? 0);
            $na += $va * $va;
        }
        foreach ($b as $vb) {
            $nb += $vb * $vb;
        }
        if ($na == 0 || $nb == 0) return 0.0;
        return $dot / (sqrt($na) * sqrt($nb));
    }
}


