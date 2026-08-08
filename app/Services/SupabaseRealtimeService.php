<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SupabaseRealtimeService
{
    public static function sendMessageEvent($message): bool
    {
        $url = config('services.supabase.url');
        $key = config('services.supabase.key');

        if (!$url || !$key) {
            Log::error('Supabase realtime configuration missing', [
                'url_exists' => !empty($url),
                'key_exists' => !empty($key),
            ]);

            return false;
        }

        $payload = [
            'message_id' => $message->id,
            'sender_id' => $message->sender_id,
            'receiver_id' => $message->receiver_id,
            'message' => $message->message ?? '',
            'type' => $message->type ?? 'text',
            'file_url' => $message->file_url,
            'file_name' => $message->file_name,
            'file_type' => $message->file_type,
            'created_at' => $message->created_at?->toISOString(),
        ];

        Log::info('SUPABASE MESSAGE EVENT CALLED', $payload);

        try {
            $response = Http::withHeaders([
                'apikey' => $key,
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=minimal',
            ])
            ->timeout(10)
            ->post(
                rtrim($url, '/') . '/rest/v1/message_events',
                $payload
            );

            Log::info('SUPABASE MESSAGE EVENT RESPONSE', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($response->failed()) {
                Log::error('SUPABASE MESSAGE EVENT INSERT FAILED', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'message_id' => $message->id,
                ]);

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            Log::error('SUPABASE MESSAGE EVENT REQUEST FAILED', [
                'message_id' => $message->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}