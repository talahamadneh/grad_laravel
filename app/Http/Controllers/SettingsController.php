<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\NotificationSetting;
use App\Models\PrivacySetting;


class SettingsController extends Controller
{


    public function changePassword(Request $request)
    {

        $request->validate([

            'current_password' => 'required',
            'password' => 'required|min:8|confirmed',

        ]);


        $user = $request->user();


        if(!Hash::check($request->current_password, $user->password))
        {
            return response()->json([
                'message'=>'Current password is incorrect'
            ],422);
        }


        $user->update([

            'password'=>Hash::make($request->password)

        ]);


        return response()->json([

            'message'=>'Password updated successfully'

        ]);

    }



    public function getNotificationSettings(Request $request)
    {

        $user = $request->user();


        $settings = NotificationSetting::firstOrCreate(

            [
                'user_id'=>$user->id
            ],

            [
                'application_updates'=>true,
                'interview_notifications'=>true,
                'job_recommendations'=>true,
                'messages'=>true,
                'profile_views'=>true,
                'resume_feedback'=>true,
                'company_applications'=>true,
                'company_messages'=>true,
                'company_matches'=>true,
                'company_deadlines'=>true,
                'company_interviews'=>true
            ]

        );


        return response()->json($settings);

    }

    public function updateNotificationSettings(Request $request)
    {

        $request->validate([

            'application_updates'=>'boolean',
            'interview_notifications'=>'boolean',
            'job_recommendations'=>'boolean',
            'messages'=>'boolean',
            'profile_views'=>'boolean',
            'resume_feedback'=>'boolean',
            'company_applications'=>'boolean',
            'company_messages'=>'boolean',
            'company_matches'=>'boolean',
            'company_deadlines'=>'boolean',
            'company_interviews'=>'boolean'

        ]);


        $settings = NotificationSetting::updateOrCreate(

            [
                'user_id'=>$request->user()->id
            ],

            $request->all()

        );


        return response()->json([

            'message'=>'Notification settings updated successfully',
            'settings'=>$settings

        ]);

    }


    public function getPrivacySettings(Request $request)
    {

        $user = $request->user();


        $settings = PrivacySetting::firstOrCreate(

            [
                'user_id'=>$user->id
            ],

            [
                'profile_visibility'=>true,
                'contact_visibility'=>false,
                'ai_resume_analysis'=>true
            ]

        );


        return response()->json($settings);

    }



    public function updatePrivacySettings(Request $request)
    {

        $request->validate([

            'profile_visibility'=>'boolean',
            'contact_visibility'=>'boolean',
            'ai_resume_analysis'=>'boolean'

        ]);


        $settings = PrivacySetting::updateOrCreate(

            [
                'user_id'=>$request->user()->id
            ],

            $request->all()

        );


        return response()->json([

            'message'=>'Privacy settings updated successfully',
            'settings'=>$settings

        ]);

    }




    public function deleteAccount(Request $request)
    {

        $request->validate([

            'password'=>'required'

        ]);


        $user = $request->user();


        if(!Hash::check($request->password, $user->password))
        {

            return response()->json([

                'message'=>'Password incorrect'

            ],422);

        }



        // delete tokens (logout all devices)

        $user->tokens()->delete();



        $user->delete();



        return response()->json([

            'message'=>'Account deleted successfully'

        ]);

    }



}
