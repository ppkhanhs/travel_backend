<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ChatbotService
{
    private string $apiKey;
    private string $model;
    private int $timeout;
    private string $apiVersion;
    private ?int $maxOutputTokens = null;
    private string $endpoint;
    private HttpFactory $http;

    public function __construct(HttpFactory $http)
    {
        $this->apiKey = (string) config('services.gemini.api_key', '');
        $this->model = (string) config('services.gemini.model', 'gemini-1.5-flash-latest');
        $this->timeout = (int) config('services.gemini.timeout', 15);

        $configuredVersion = (string) config('services.gemini.version', '');
        $this->apiVersion = $configuredVersion !== ''
            ? (($configuredVersion === 'v1' && str_starts_with($this->model, 'gemini-2')) ? 'v1beta' : $configuredVersion)
            : (str_starts_with($this->model, 'gemini-2') ? 'v1beta' : 'v1');

        $maxTokens = config('services.gemini.max_output_tokens');
        $this->maxOutputTokens = $maxTokens !== null ? (int) $maxTokens : null;

        $this->http = $http;
        $this->endpoint = sprintf(
            'https://generativelanguage.googleapis.com/%s/models/%s:generateContent',
            $this->apiVersion,
            $this->model
        );
    }

    public function ask(string $systemPrompt, string $userPrompt, array $history = []): string
    {
        if ($this->apiKey === '') {
            throw ValidationException::withMessages([
                'message' => ['Chatbot service is not configured.'],
            ]);
        }

        $contents = [];

        foreach ($history as $message) {
            $contents[] = [
                'role' => $message['role'],
                'parts' => [
                    [
                        'text' => $message['content'],
                    ],
                ],
            ];
        }

        $contents[] = [
            'role' => 'user',
            'parts' => [
                [
                    'text' => $userPrompt,
                ],
            ],
        ];

        $payload = [
            'system_instruction' => [
                'parts' => [
                    [
                        'text' => $systemPrompt,
                    ],
                ],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.7,
                'topP' => 0.9,
                'maxOutputTokens' => $this->maxOutputTokens ?: null,
            ],
        ];

        $response = $this->http
            ->timeout($this->timeout)
            ->withHeaders([
                'x-goog-api-key' => $this->apiKey,
            ])
            ->post($this->endpoint, $payload);

        $this->guardResponse($response);

        $data = $response->json();
        $message = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!$message) {
            throw ValidationException::withMessages([
                'message' => ['Chatbot service returned an empty response.'],
            ]);
        }

        return (string) $message;
    }

    private function guardResponse(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $body = $response->json();
        $error = $body['error']['message'] ?? $response->body();

        Log::error('[Chatbot] Gemini API error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw ValidationException::withMessages([
            'message' => ['Chatbot service error: ' . $error],
        ]);
    }
}
