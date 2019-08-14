<?php

namespace Increment\Account\Http;

use Increment\Account\Models\Account;
use Increment\Account\Models\AccountInformation;
use Increment\Account\Models\BillingInformation;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\APIController;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
class AccountController extends APIController
{
    function __construct(){  
      $this->model = new Account();
      $this->validation = array(  
        "email" => "unique:accounts",
        "username"  => "unique:accounts"
      );
    }

    public function create(Request $request){
     $request = $request->all();
     $referralCode = $request['referral_code'];
     $invitationPassword = $request['password'];
     $dataAccount = array(
      'code'  => $this->generateCode(),
      'password'        => Hash::make($request['password']),
      'status'          => 'NOT_VERIFIED',
      'email'           => $request['email'],
      'username'        => $request['username'],
      'account_type'    => $request['account_type'],
      'created_at'      => Carbon::now()
     );
     $this->model = new Account();
     $this->insertDB($dataAccount);
     $accountId = $this->response['data'];

     if($accountId){
       $this->createDetails($accountId, $request['account_type']);
       //send email verification here
       if($referralCode != null){
          app('Increment\Plan\Http\InvitationController')->confirmReferral($referralCode);
       }
       if(env('SUB_ACCOUNT') == true){
          $status = $request['status'];
          app('Increment\Account\Http\SubAccountController')->createByParams($request['account_id'], $accountId, $status);
          app('App\Http\Controllers\EmailController')->loginInvitation($accountId, $invitationPassword);
       }else{
          app('App\Http\Controllers\EmailController')->verification($accountId);
       }
     }
    
     return $this->response();
    }

    public function createDetails($accountId, $type){
      $info = new AccountInformation();
      $info->account_id = $accountId;
      $info->created_at = Carbon::now();
      $info->save();

      $billing = new BillingInformation();
      $billing->account_id = $accountId;
      $billing->created_at = Carbon::now();
      $billing->save();
    }

    public function generateCode(){
      $code = substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 32);
      $codeExist = Account::where('code', '=', $code)->get();
      if(sizeof($codeExist) > 0){
        $this->generateCode();
      }else{
        return $code;
      }
    }

    public function verify(Request $request){
      $data = $request->all();
      $this->model = new Account();
      $this->retrieveDB($data);
      if(sizeof($this->response['data']) > 0){
        app('App\Http\Controllers\EmailController')->verification($this->response['data'][0]['id']);  
      }
      return $this->response();
    }

    public function updateByVerification(Request $request){
      $data = $request->all();
      $this->model = new Account();
      $this->updateDB($data);
      return $this->response();
    }

    public function requestReset(Request $request){
      $data = $request->all();
      $result = Account::where('email', '=', $data['email'])->get();
      if(sizeof($result) > 0){
        app('App\Http\Controllers\EmailController')->resetPassword($result[0]['id']);
        return response()->json(array('data' => true));
      }else{
        return response()->json(array('data' => false));
      }
    }

    public function update(Request $request){ 
      $data = $request->all();
      $result = Account::where('code', '=', $data['code'])->where('username', '=', $data['username'])->get();
      if(sizeof($result) > 0){
        $updateData = array(
          'id'        => $result[0]['id'],
          'password'  => Hash::make($data['password'])
        );
        $this->model = new Account();
        $this->updateDB($updateData);
        if($this->response['data'] == true){
          dispatch(new Email($result[0], 'reset_password'));
          return $this->response();
        }else{
          return response()->json(array('data' => false));
        }
      }else{
        return response()->json(array('data' => false));
      }
    }

    public function updatePasswordViaSubAccount($accountId, $password){
      $invitationPassword = $password;
      $password = Hash::make($password);
      Account::where('id', '=', $accountId)->update(array(
        'password' => $password
      ));
      app('App\Http\Controllers\EmailController')->loginInvitation($accountId, $invitationPassword);
      return true;
    }
    public function hashPassword($password){
      $data['password'] = Hash::make($password);
      return $data;
    }

    public function retrieve(Request $request){
      $data = $request->all();
      $this->model = new Account();
      $result = $this->retrieveDB($data);

      if(sizeof($result) > 0){
        $i = 0;
        foreach ($result as $key) {
          $result[$i] = $this->retrieveDetailsOnLogin($result[$i]);
          $i++;
        }
        return response()->json(array('data' => $result));
      }else{
        return $this->response();
      }
    }

    public function retrieveById($accountId){
      return Account::where('id', '=', $accountId)->get();
    }

    public function updatePassword(Request $request){ 
      $data = $request->all();
      $data['password'] = Hash::make($data['password']);
      $this->updateDB($data);
      return $this->response();
    }

    public function updateEmail(Request $request){
      $request = $request->all();
      $result = Account::where('email', '=', $request['email'])->get();
      $text = array('email' => $request['email']);
      if(sizeof($result) <= 0 && $this->customValidate($text) == true){
        $this->model = new Account();
        $updateData = array(
          'id' => $request['id'],
          'email' => $request['email']
        );
        $this->updateDB($updateData);
        if($this->response['data'] == true){
          $account = Account::where('id', '=', $request['id'])->get();
          return $this->response();
        }else{
          return response()->json(array('data' => false, 'error' => 'Unable to update please contact the support.'));
        }
      }else{
        return response()->json(array('data' => false, 'error' => 'Email already used.'));
      }
    }

    public function customValidate($text){
      $validation = array('email' => 'required|email'); 
      return $this->validateReply($text, $validation);
    }

    public function validateReply($text, $validation){
      $validator = Validator::make($text, $validation);
      if($validator->fails()){
        return false;
      }
      else
        return true;
    }

}