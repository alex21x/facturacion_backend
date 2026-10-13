<?php

namespace App\Infrastructure\Gateways;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenRouterGateway
{
    public function completeJson(string $systemPrompt, string $userPrompt): array
    {
        $apiKey = trim((string) config('ai.openrouter.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('El agente IA no está configurado en el servidor.');
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('ai.openrouter.timeout', 45))
            ->post(rtrim((string) config('ai.openrouter.base_url'), '/') . '/chat/completions', [
                'model' => (string) config('ai.openrouter.model', 'openrouter/free'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
            ]);

        if ($response->failed()) {
            logger()->warning('OpenRouter request failed', ['status' => $response->status()]);
            throw new RuntimeException('No se pudo consultar el agente IA.');
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        if (is_array($content)) {
            $content = collect($content)
                ->where('type', 'text')
                ->pluck('text')
                ->implode('');
        }

        $decoded = $this->decodeJsonContent((string) $content);
        if (!is_array($decoded)) {
            logger()->warning('OpenRouter returned a non-JSON completion', [
                'model' => (string) config('ai.openrouter.model', 'openrouter/free'),
                'content_length' => strlen((string) $content),
            ]);
            throw new RuntimeException('El agente IA devolvió una respuesta inválida.');
        }

        return $decoded;
    }

    private function decodeJsonContent(string $content): ?array
    {
        $content = trim($content);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $content, $match) === 1) {
            $decoded = json_decode($match[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $firstBrace = strpos($content, '{');
        $lastBrace = strrpos($content, '}');
        if ($firstBrace === false || $lastBrace === false || $lastBrace <= $firstBrace) {
            return null;
        }

        $decoded = json_decode(substr($content, $firstBrace, $lastBrace - $firstBrace + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
