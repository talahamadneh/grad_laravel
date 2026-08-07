<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SupabaseRealtimeService
{
    public static function sendMessageEvent($message)
    {
        Log::info("SUPABASE EVENT CALLED", [
            'message_id' => $message->id
        ]);

        $response = Http::withHeaders([
            'apikey' => config('services.supabase.key'),
            'Authorization' => 'Bearer ' . config('services.supabase.key'),
            'Content-Type' => 'application/json',
        ])->post(
            config('services.supabase.url') . '/rest/v1/message_events',
            [
                'message_id' => $message->id,
                'sender_id' => $message->sender_id,
                'receiver_id' => $message->receiver_id,
                'message' => $message->message,
                'created_at' => now(),

            ]
        );

        Log::info("SUPABASE RESPONSE", [
            'status' => $response->status(),
            'body' => $response->body()
        ]);
    }
}