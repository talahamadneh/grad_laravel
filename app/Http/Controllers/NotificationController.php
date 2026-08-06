<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Notification;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(function ($notification) {

                return [

                    "id" => $notification->id,

                    "title" => $notification->title,

                    "message" => $notification->message,

                    "is_read" => (bool) $notification->is_read,

                    "time" => $notification->created_at->diffForHumans(),

                    "created_at" => $notification->created_at,

                ];
            });

        return response()->json([
            "unread_count" => $notifications->where('is_read', false)->count(),
            "notifications" => $notifications
        ]);
    }


    public function unreadCount(Request $request)
    {
        return response()->json([

            "unread_count" => Notification::where('user_id', $request->user()->id)
                ->where('is_read', false)
                ->count()

        ]);
    }


    public function markAsRead(Request $request, $id)
    {
        $notification = Notification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$notification) {

            return response()->json([
                "message" => "Notification not found."
            ], 404);

        }

        $notification->update([
            "is_read" => true
        ]);

        return response()->json([
            "message" => "Notification marked as read."
        ]);
    }


    public function markAllAsRead(Request $request)
    {
        Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update([
                "is_read" => true
            ]);

        return response()->json([
            "message" => "All notifications marked as read."
        ]);
    }


    public function destroy(Request $request, $id)
    {
        $notification = Notification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$notification) {

            return response()->json([
                "message" => "Notification not found."
            ], 404);

        }

        $notification->delete();

        return response()->json([
            "message" => "Notification deleted successfully."
        ]);
    }
}