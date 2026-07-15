<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;


class MessageController extends Controller
{
  
    public function index(Request $request)
    {
        $user = $request->user();

        $messages = Message::where('sender_id', $user->id)
            ->orWhere('receiver_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        $conversations = [];

        foreach ($messages as $message) {

            $otherUserId = $message->sender_id == $user->id
                ? $message->receiver_id
                : $message->sender_id;

            if (isset($conversations[$otherUserId])) {
                continue;
            }

            $otherUser = User::find($otherUserId);

            if (!$otherUser) {
                continue;
            }

            $name = $otherUser->name;
            $avatar = null;

            if ($otherUser->role == "Company") {

                $company = $otherUser->company;

                if ($company) {
                    $name = $company->company_name;
                    $avatar = $company->logo;
                }

            } elseif ($otherUser->role == "Student") {

                $student = $otherUser->student;

                if ($student) {
                    $avatar = $student->avatar;
                }
            }

            $unread = Message::where('sender_id', $otherUserId)
                ->where('receiver_id', $user->id)
                ->count();

            $conversations[$otherUserId] = [
                "user_id" => $otherUser->id,
                "name" => $name,
                "avatar" => $avatar,
                "last_message" => $message->message,
                "last_time" => $message->created_at->diffForHumans(),
                "unread" => $unread,
            ];
        }

        return response()->json(array_values($conversations));
    }

    

    public function show(Request $request, User $user)
    {
        $me = $request->user();

        $messages = Message::where(function ($q) use ($me, $user) {
            $q->where('sender_id', $me->id)
                ->where('receiver_id', $user->id);
        })
            ->orWhere(function ($q) use ($me, $user) {
                $q->where('sender_id', $user->id)
                    ->where('receiver_id', $me->id);
            })
            ->orderBy('created_at')
            ->get();

        return response()->json(

            $messages->map(function ($message) use ($me) {

                return [

                    "id" => $message->id,

                    "from" => $message->sender_id == $me->id
                        ? "me"
                        : "them",

                    "text" => $message->message,

                    "time" => $message->created_at->format("h:i A"),

                    "created_at" => $message->created_at,
                ];
            })

        );
    }

   

    public function store(Request $request)
    {
        $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'message' => 'required|string|max:2000',
        ]);

        $message = Message::create([
            'sender_id' => $request->user()->id,
            'receiver_id' => $request->receiver_id,
            'message' => $request->message,
        ]);

        return response()->json([
            "success" => true,
            "message" => $message
        ], 201);
    }
}