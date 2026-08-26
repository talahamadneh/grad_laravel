<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Exception;
use Throwable;

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
            try {
                $response = Http::timeout(30)
                    ->withToken($key)
                    ->post($this->apiUrl, [
                        'model' => $this->model,
                        'messages' => [
                            ['role' => 'user', 'content' => $prompt],
                        ],
                        'temperature' => 0.7,
                    ]);
            } catch (Throwable $exception) {
                $lastError = $this->temporaryFailureMessage($exception);
                continue;
            }

            if ($response->successful()) {
                $data = $response->json();

                return $data['choices'][0]['message']['content'] ?? '';
            }

            $status = $response->status();
            $lastError = $this->safeErrorMessage($status, $this->responseErrorMessage($response));

            if ($this->shouldTryNextKey($status, $lastError)) {
                continue;
            }

            throw new Exception($lastError);
        }

        throw new Exception(
            'All Groq API keys failed. Last error: ' . ($lastError ?: 'provider unavailable')
        );
    }

    public function generateJson(string $prompt, ?int $maxCompletionTokens = null): string
    {
        if (empty($this->apiKeys)) {
            throw new Exception('No Groq API keys configured');
        }

        $lastError = null;

        foreach ($this->apiKeys as $key) {
            try {
                $response = Http::timeout(45)
                    ->withToken($key)
                    ->post($this->apiUrl, [
                        'model' => $this->model,
                        'messages' => [
                            ['role' => 'user', 'content' => $prompt],
                        ],
                        'temperature' => 0.2,
                        'response_format' => ['type' => 'json_object'],
                        'max_completion_tokens' => $maxCompletionTokens ?? (int) config('services.groq.interview_max_completion_tokens', 3000),
                    ]);
            } catch (Throwable $exception) {
                $lastError = $this->temporaryFailureMessage($exception);
                continue;
            }

            if ($response->successful()) {
                $data = $response->json();

                return $data['choices'][0]['message']['content'] ?? '';
            }

            $status = $response->status();
            $lastError = $this->safeErrorMessage($status, $this->responseErrorMessage($response));

            if ($this->shouldTryNextKey($status, $lastError)) {
                continue;
            }

            throw new Exception($lastError);
        }

        throw new Exception(
            'All Groq API keys failed. Last error: ' . ($lastError ?: 'provider unavailable')
        );
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
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'failed to generate json')
            || str_contains($message, 'failed_generation')
            || str_contains($message, 'structured output generation failure')
            || str_contains($message, 'response format generation failure');
    }

    private function responseErrorMessage($response): string
    {
        $parts = array_filter([
            $response->json('error.message'),
            $response->json('error.code'),
            $response->json('error.type'),
        ]);

        return !empty($parts) ? implode(' ', $parts) : $response->body();
    }

    private function temporaryFailureMessage(Throwable $exception): string
    {
        return $this->safeErrorMessage(0, $exception->getMessage() ?: 'temporary provider failure');
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
