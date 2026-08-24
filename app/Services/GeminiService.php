<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Throwable;

class GeminiService
{
    protected array $apiKeys;

    protected string $model;

    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct()
    {
        $this->apiKeys = config('services.gemini.keys', []);
        $this->model = config('services.gemini.model', 'gemini-flash-lite-latest');
    }

    public function generate(string $prompt): string
    {
        return $this->request($prompt);
    }

    public function generateJson(string $prompt, ?int $maxOutputTokens = null): string
    {
        return $this->request($prompt, [
            'responseMimeType' => 'application/json',
            'maxOutputTokens' => $maxOutputTokens ?? (int) config('services.gemini.interview_max_output_tokens', 8192),
            'temperature' => 0.2,
        ]);
    }

    private function request(string $prompt, array $generationConfig = []): string
    {
        if (empty($this->apiKeys)) {
            throw new Exception('No Gemini API keys configured');
        }

        $lastError = null;

        foreach ($this->apiKeys as $key) {
            try {
                $response = Http::timeout(45)
                    ->post($this->url($key), [
                        'contents' => [
                            [
                                'role' => 'user',
                                'parts' => [
                                    ['text' => $prompt],
                                ],
                            ],
                        ],
                        'generationConfig' => array_merge([
                            'temperature' => 0.2,
                        ], $generationConfig),
                    ]);
            } catch (Throwable) {
                $lastError = 'Gemini request failed before receiving a response.';
                continue;
            }

            if ($response->successful()) {
                $data = $response->json();

                return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            }

            $status = $response->status();
            $lastError = $response->body();

            if ($status === 429 || $status === 503) {
                continue;
            }

            throw new Exception($lastError);
        }

        throw new Exception('All Gemini API keys failed. Last error: ' . $lastError);
    }

    private function url(string $key): string
    {
        return "{$this->baseUrl}/{$this->model}:generateContent?key={$key}";
    }
}
