<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\SupabaseRealtimeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $closedUserIds = DB::table('closed_conversations')
            ->where('user_id', $user->id)
            ->pluck('other_user_id')
            ->toArray();

        $messages = Message::where(function ($query) use ($user) {
            $query->where('sender_id', $user->id)
                ->orWhere('receiver_id', $user->id);
        })
            ->where(function ($query) use ($closedUserIds) {
                $query->whereNotIn('sender_id', $closedUserIds)
                    ->whereNotIn('receiver_id', $closedUserIds);
            })
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

            if ($otherUser->role === 'Company') {
                $company = $otherUser->company;

                if ($company) {
                    $name = $company->company_name;
                    $avatar = $company->logo;
                }
            } elseif ($otherUser->role === 'Student') {
                $student = $otherUser->student;

                if ($student) {
                    $avatar = $student->avatar;
                }
            }

            $unread = Message::where('sender_id', $otherUserId)
                ->where('receiver_id', $user->id)
                ->where('is_read', false)
                ->count();

            $lastMessage = $message->message;

            if ($message->type === 'image') {
                $lastMessage = '📷 Image';
            } elseif ($message->type === 'file') {
                $lastMessage = '📎 ' . ($message->file_name ?? 'File');
            } elseif ($message->type === 'audio') {
                $lastMessage = '🎤 Voice message';
            }

            $conversations[$otherUserId] = [
                'user_id' => $otherUser->id,
                'name' => $name,
                'avatar' => $avatar,
                'last_message' => $lastMessage,
                'last_time' => $message->created_at->diffForHumans(),
                'unread' => $unread,
            ];
        }

        return response()->json(array_values($conversations));
    }

    public function searchRecipient(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $me = $request->user();

        $recipient = User::where('email', $request->email)
            ->where('id', '!=', $me->id)
            ->whereIn('role', ['Student', 'Company'])
            ->first();

        if (!$recipient) {
            return response()->json([
                'success' => false,
                'message' => 'No student or company found with this email.',
            ], 404);
        }

        $name = $recipient->name;
        $avatar = null;

        if ($recipient->role === 'Company') {
            $company = $recipient->company;

            if ($company) {
                $name = $company->company_name;
                $avatar = $company->logo;
            }
        }

        if ($recipient->role === 'Student') {
            $student = $recipient->student;

            if ($student) {
                $avatar = $student->avatar;
            }
        }

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $recipient->id,
                'name' => $name,
                'email' => $recipient->email,
                'role' => $recipient->role,
                'avatar' => $avatar,
            ],
        ]);
    }

    public function show(Request $request, User $user)
    {
        $me = $request->user();

        Message::where('sender_id', $user->id)
            ->where('receiver_id', $me->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
            ]);

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
                    'id' => $message->id,
                    'from' => $message->sender_id == $me->id
                        ? 'me'
                        : 'them',
                    'text' => $message->message,
                    'type' => $message->type ?? 'text',
                    'file_url' => $message->file_url,
                    'file_name' => $message->file_name,
                    'file_type' => $message->file_type,
                    'time' => $message->created_at->format('h:i A'),
                    'is_read' => $message->is_read,
                    'created_at' => $message->created_at,
                ];
            })
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'message' => 'nullable|string|max:2000',
            'type' => 'required|in:text,image,file,audio',
            'file' => 'nullable|file|max:20480',
        ]);

        if ($request->receiver_id == $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot send a message to yourself.',
            ], 422);
        }

        if ($request->type === 'text' && !$request->filled('message')) {
            return response()->json([
                'success' => false,
                'message' => 'Message is required for text messages.',
            ], 422);
        }

        if ($request->type !== 'text' && !$request->hasFile('file')) {
            return response()->json([
                'success' => false,
                'message' => 'File is required for attachments.',
            ], 422);
        }

        $receiver = User::find($request->receiver_id);

        if (!$receiver || !in_array($receiver->role, ['Student', 'Company'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid recipient.',
            ], 422);
        }

        DB::table('closed_conversations')
            ->where(function ($query) use ($request) {
                $query->where('user_id', $request->user()->id)
                    ->where('other_user_id', $request->receiver_id);
            })
            ->orWhere(function ($query) use ($request) {
                $query->where('user_id', $request->receiver_id)
                    ->where('other_user_id', $request->user()->id);
            })
            ->delete();

        $fileUrl = null;
        $fileName = null;
        $fileType = null;

        if ($request->hasFile('file')) {
            $file = $request->file('file');

            $fileName = $file->getClientOriginalName();
            $fileType = $file->getMimeType();

            $path = $file->store('messages', 'public');

            $fileUrl = asset('storage/' . $path);
        }

        $message = Message::create([
            'sender_id' => $request->user()->id,
            'receiver_id' => $request->receiver_id,
            'message' => $request->input('message') ?? '',
            'type' => $request->type,
            'file_url' => $fileUrl,
            'file_name' => $fileName,
            'file_type' => $fileType,
            'is_read' => false,
        ]);

        SupabaseRealtimeService::sendMessageEvent($message);

        if ($receiver->role === 'Student' && $request->user()->role === 'Company') {
            $sender = $request->user();

            $companyName = $sender->company->company_name ?? $sender->name;

            NotificationService::newMessageFromCompany(
                $request->receiver_id,
                $companyName
            );
        }

        if ($receiver->role === 'Company' && $request->user()->role === 'Student') {
            $sender = $request->user();

            $studentName = $sender->name;

            NotificationService::newMessageForCompany(
                $request->receiver_id,
                $studentName
            );
        }

        return response()->json([
            'success' => true,
            'message' => $message,
        ], 201);
    }

    public function findUserByEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        $name = $user->name;
        $avatar = null;

        if ($user->role === 'Company') {
            $company = $user->company;

            if ($company) {
                $name = $company->company_name;
                $avatar = $company->logo;
            }
        }

        if ($user->role === 'Student') {
            $student = $user->student;

            if ($student) {
                $avatar = $student->avatar;
            }
        }

        return response()->json([
            'id' => $user->id,
            'name' => $name,
            'email' => $user->email,
            'role' => $user->role,
            'avatar' => $avatar,
        ]);
    }

    public function destroyConversation(Request $request, User $user)
    {
        $me = $request->user();

        Message::where(function ($query) use ($me, $user) {
            $query->where('sender_id', $me->id)
                ->where('receiver_id', $user->id);
        })
            ->orWhere(function ($query) use ($me, $user) {
                $query->where('sender_id', $user->id)
                    ->where('receiver_id', $me->id);
            })
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Conversation deleted successfully.',
        ]);
    }

    public function blockUser(Request $request, User $user)
    {
        $me = $request->user();

        if ($user->id === $me->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot block yourself.',
            ], 422);
        }

        $me->blockedUsers()->syncWithoutDetaching([
            $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User blocked successfully.',
        ]);
    }

    public function reportUser(Request $request, User $user)
    {
        $me = $request->user();

        if ($user->id === $me->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot report yourself.',
            ], 422);
        }

        $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        DB::table('message_reports')->insert([
            'reporter_id' => $me->id,
            'reported_user_id' => $user->id,
            'reason' => $request->input('reason'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User reported successfully.',
        ]);
    }

    public function closeConversation(Request $request, User $user)
    {
        $me = $request->user();

        if ($user->id === $me->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot close a conversation with yourself.',
            ], 422);
        }

        DB::table('closed_conversations')->updateOrInsert(
            [
                'user_id' => $me->id,
                'other_user_id' => $user->id,
            ],
            [
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Conversation closed successfully.',
        ]);
    }

    public function reopenConversation(Request $request, User $user)
    {
        $me = $request->user();

        DB::table('closed_conversations')
            ->where('user_id', $me->id)
            ->where('other_user_id', $user->id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Conversation reopened successfully.',
        ]);
    }
}

