<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Exception;

class GeminiService
{
    protected array $apiKeys;

    protected string $model = 'gemini-2.0-flash';

    public function __construct()
    {
        $this->apiKeys = config('services.gemini.keys', []);
    }

    public function generate(string $prompt): string
    {
        if (empty($this->apiKeys)) {
            throw new Exception('No Gemini API keys configured');
        }

        $lastError = null;

        foreach ($this->apiKeys as $key) {

            $response = Http::timeout(30)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$key}",
                [
                    'contents' => [
                        [
                            'parts' => [
                                [
                                    'text' => $prompt
                                ]
                            ]
                        ]
                    ]
                ]
            );

            if ($response->successful()) {

                $data = $response->json();

                return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            }

            $status = $response->status();
            $lastError = $response->body();

            // Retry on temporary errors / quota limits
            if ($status === 429 || $status === 503) {
                continue;
            }

            throw new Exception($lastError);
        }

        throw new Exception(
            'All Gemini API keys failed. Last error: ' . $lastError
        );
    }
}