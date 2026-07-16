<?php

namespace App\Services;

use App\Models\Notification;


class NotificationService
{


    public function send(
        $userId,
        $title,
        $message
    )
    {

        return Notification::create([

            'user_id'=>$userId,

            'title'=>$title,

            'message'=>$message,

            'is_read'=>false

        ]);

    }


}