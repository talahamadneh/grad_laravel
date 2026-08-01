<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Exception;

class GroqService
{
    protected array $apiKeys;

    protected string $model;

    protected string $apiUrl = 'https://api.groq.com/openai/v1/chat/completions';

    public function __construct()
    {
        $this->apiKeys = config('services.groq.keys', []);
        $this->model = config('services.groq.model', 'llama-3.3-70b-versatile');
    }

    public function generate(string $prompt): string
    {
        if (empty($this->apiKeys)) {
            throw new Exception('No Groq API keys configured');
        }

        $lastError = null;

        foreach ($this->apiKeys as $key) {

            $response = Http::timeout(30)
                ->withToken($key)
                ->post($this->apiUrl, [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.7,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                return $data['choices'][0]['message']['content'] ?? '';
            }

            $status = $response->status();
            $lastError = $response->body();

            if ($status === 429 || $status === 503) {
                continue;
            }

            throw new Exception($lastError);
        }

        throw new Exception(
            'All Groq API keys failed. Last error: ' . $lastError
        );
    }
}