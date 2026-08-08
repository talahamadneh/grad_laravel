<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SupabaseNotificationService
{
    public static function send(Notification $notification): bool
    {
        $url = config('services.supabase.url');
        $key = config('services.supabase.key');

        if (!$url || !$key) {
            Log::error('Supabase notification configuration missing', [
                'url_exists' => !empty($url),
                'key_exists' => !empty($key),
            ]);

            return false;
        }

        $payload = [
            'id' => $notification->id,
            'user_id' => $notification->user_id,
            'title' => $notification->title,
            'message' => $notification->message,
            'is_read' => (bool) $notification->is_read,
            'created_at' => $notification->created_at?->toISOString(),
            'updated_at' => $notification->updated_at?->toISOString(),
        ];

        Log::info('SUPABASE NOTIFICATION EVENT CALLED', $payload);

        try {
            $response = Http::withHeaders([
                'apikey' => $key,
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=minimal',
            ])
            ->timeout(10)
            ->post(
                rtrim($url, '/') . '/rest/v1/notifications',
                $payload
            );

            Log::info('SUPABASE NOTIFICATION RESPONSE', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($response->failed()) {
                Log::error('SUPABASE NOTIFICATION INSERT FAILED', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'notification_id' => $notification->id,
                ]);

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            Log::error('SUPABASE NOTIFICATION REQUEST FAILED', [
                'notification_id' => $notification->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}