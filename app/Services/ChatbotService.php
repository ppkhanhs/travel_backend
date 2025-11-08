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
    private HttpFactory $http;

    public function __construct(HttpFactory $http)
    {
        $this->apiKey = (string) config('services.openai.api_key', '');
        $this->model = (string) config('services.openai.model', 'gpt-4o');
        $this->timeout = (int) config('services.openai.timeout', 15);
        $this->http = $http;
    }

    public function ask(string $systemPrompt, string $userPrompt): string
    {
        if ($this->apiKey === '') {
            throw ValidationException::withMessages([
                'message' => ['Chatbot service is not configured.'],
            ]);
        }

        $payload = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
            'temperature' => 0.7,
            'top_p' => 0.9,
        ];

        $response = $this->http
            ->timeout($this->timeout)
            ->withToken($this->apiKey)
            ->post('https://api.openai.com/v1/chat/completions', $payload);

        $this->guardResponse($response);

        $data = $response->json();
        $message = $data['choices'][0]['message']['content'] ?? null;

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

        Log::error('[Chatbot] OpenAI API error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw ValidationException::withMessages([
            'message' => ['Chatbot service error: ' . $error],
        ]);
    }
}

