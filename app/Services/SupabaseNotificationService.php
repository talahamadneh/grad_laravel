<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SupabaseNotificationService
{
    public static function send(Notification $notification)
    {
        Log::info('SUPABASE NOTIFICATION EVENT CALLED', [
            'notification_id' => $notification->id
        ]);

        $response = Http::withHeaders([
            'apikey' => config('services.supabase.key'),
            'Authorization' => 'Bearer ' . config('services.supabase.key'),
            'Content-Type' => 'application/json',
            'Prefer' => 'return=minimal',
        ])->post(
            config('services.supabase.url') . '/rest/v1/notifications',
            [
                'id' => $notification->id,
                'user_id' => $notification->user_id,
                'title' => $notification->title,
                'message' => $notification->message,
                'is_read' => $notification->is_read,
                'created_at' => $notification->created_at,
                'updated_at' => $notification->updated_at,
            ]
        );

        Log::info('SUPABASE NOTIFICATION RESPONSE', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if ($response->failed()) {
            Log::error('SUPABASE NOTIFICATION INSERT FAILED', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}