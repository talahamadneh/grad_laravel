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
            } catch (Throwable $exception) {
                $lastError = $this->safeErrorMessage(0, $exception->getMessage() ?: 'temporary provider failure');
                continue;
            }

            if ($response->successful()) {
                $data = $response->json();

                return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            }

            $status = $response->status();
            $lastError = $this->safeErrorMessage($status, $response->json('error.message') ?? $response->body());

            if ($this->shouldTryNextKey($status, $lastError)) {
                continue;
            }

            throw new Exception($lastError);
        }

        throw new Exception('All Gemini API keys failed. Last error: ' . ($lastError ?: 'provider unavailable'));
    }

    private function url(string $key): string
    {
        return "{$this->baseUrl}/{$this->model}:generateContent?key={$key}";
    }

    private function shouldTryNextKey(int $status, string $message): bool
    {
        $message = strtolower($message);

        return in_array($status, [429, 503], true)
            || str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'connection')
            || str_contains($message, 'network')
            || str_contains($message, 'could not resolve')
            || str_contains($message, 'service unavailable')
            || str_contains($message, 'temporarily unavailable');
    }

    private function safeErrorMessage(int $status, string $message): string
    {
        $message = trim($message);

        if ($status === 429) {
            return 'rate limit';
        }

        if ($status === 503) {
            return 'provider unavailable';
        }

        if ($message === '') {
            return $status > 0 ? "provider error {$status}" : 'temporary provider failure';
        }

        foreach ($this->apiKeys as $key) {
            if ($key !== '') {
                $message = str_replace($key, '[redacted]', $message);
            }
        }
        $message = preg_replace('/(key=)[^&\s)]+/i', '$1[redacted]', $message) ?? $message;

        return substr($message, 0, 240);
    }
}
