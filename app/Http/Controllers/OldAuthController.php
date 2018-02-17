<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;


use JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Validator;
use App\User;
use Hash, Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Mail\Message;


class OldAuthController extends Controller
{

    /**
     * API Send Verification
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendVerification(Request $request)
    {
        $rules = [
            'email' => 'required',
            'v_type' => 'required',
        ];

        $input = $request->only(
            'email',
            'v_type'
        );

        $validator = Validator::make($input, $rules);

        if($validator->fails()) {
            $error = $validator->messages()->toJson();
            return response()->json(['success'=> false, 'error'=> $error]);
        }
        $user = User::where('email', $request->email)->first();

        if (!$user) return response()->json(['success'=> false, 'error'=> "User was not found."]);

        $verification_code = rand(10000, 99999);
        $confirmation_code = str_random(30); //Generate confirmation code
        $type = $request->v_type;

        if ($type === "sms") $user->verification_code = $verification_code;
        else $user->confirmation_code = $confirmation_code;
        $user->save();

        if($type === "sms"){
            //SMS Verification
            $decoded_response = $this->sendSMSVerification($verification_code,$request->phone_number);

            foreach ( $decoded_response['messages'] as $message ) {
                if ($message['status'] == 0) {
                    return response()->json(['success'=> true, 'message'=> "Verification code has been sent to ".$request->phone_number+'.']);
                } else {
                    return response()->json(['success'=> false, 'error'=> "Error {$message['status']} {$message['error-text']}"]);
                }
            }

        }elseif($request->v_type === "email"){
            //Email verification
            $email =$user->email;
            $name =$user->name;
            Mail::send('email.verify', ['confirmation_code' => $confirmation_code],
                function($m) use ($email, $name){
                    $m->from($_ENV['MAIL_USERNAME'], 'Test API');
                    $m->to($email, $name)
                        ->subject('Verify your email address');
                });
            return response()->json(['success'=> true, 'message'=> 'Please check your email.']);
        }
    }

    /**
     * API Verify User
     *
     * @param Request $request
     */
    public function verifyUser($type, $code)
    {
        if(!$code) return "Invalid link/code";

        if ($type === "email") $user = User::where('confirmation_code', $code)->first();
        else $user = User::where('verification_code', $code)->first();

        if (!$user) {
            if ($type === "email"){
                return response()->json(['success'=> false, 'error'=> "User Not Found."]);
            }else{
                return response()->json(['success'=> false, 'error'=> "Incorrect Code. Please make sure you entered the correct code."]);
            }
        }

        $user->confirmed = 1;
        if ($type === "email") $user->confirmation_code = null;
        else $user->verification_code = null;

        $user->save();

        if ($type === "email"){
            $email =$user->email;
            $name =$user->name;
            Mail::send('email.welcome', ['email' => $email, 'name' => $name],
                function ($m) use ($email, $name) {
                    $m->from($_ENV['MAIL_USERNAME'], 'Test API');
                    $m->to($email, $name)->subject('Welcome To Test API');
                });
        }

        return response()->json(['success'=> true, 'message'=> 'You have successfully verified your account.']);
    }

    /**
     * API Resend Verification
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resendVerification(Request $request)
    {
        $user = User::where('email', $request->email)->first();

        if (!$user) return response()->json(['success'=> false, 'error'=> "Your email address was not found."]);

        $confirmation_code = str_random(30); //Generate confirmation code
        $user->confirmation_code = $confirmation_code;
        $user->save();

        $email =$user->email;
        $name =$user->name;

        Mail::send('email.verify', ['confirmation_code' => $confirmation_code],
            function($m) use ($email, $name){
                $m->from('[from_email_add]', 'Test API');
                $m->to($email, $name)
                    ->subject('Verify your email address');
            });

        return response()->json(['success'=> true, 'message'=> 'A new verification email has been sent! Please check your email.']);
    }

    /**
     * Send SMS Verification Code
     *
     * @param $verification_code, $phone_number
     */
    public function sendSMSVerification($verification_code, $phone_number){
        $otp_prefix = ':';

        //Your message to send, Add URL encoding here.
        $message = "Hello! Welcome to TestApp. Your Verification code is $otp_prefix $verification_code";

        $url = 'https://rest.nexmo.com/sms/json?'.http_build_query(
                [
                    'api_key' =>  $_ENV['NEXMO_API_KEY'],
                    'api_secret' => $_ENV['NEXMO_API_SECRET'],
                    'to' => $phone_number,
                    'from' => $_ENV['NEXMO_FROM_NUMBER'],
                    'text' => $message
                ]
            );

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);

        //Decode the json object you retrieved when you ran the request.
        $decoded_response = json_decode($response, true);
        return $decoded_response;
    }


}