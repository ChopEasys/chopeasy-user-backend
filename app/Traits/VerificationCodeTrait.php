<?php

namespace App\Traits;

use App\Enums\IdentifierTypeEnums;
use App\Enums\VerificationCodeStatusEnums;
use App\Exceptions\BadRequestException;
use App\Helpers\GeneralHelper;
use App\Mail\LoginOTPEmail;
use App\Mail\Member\CreatePasswordMail;
use App\Mail\Member\ForgotPasswordMail;
use App\Mail\Member\VotingMail;
use App\Mail\Relative\CreatePasswordMail as RelativeCreatePasswordMail;
use App\Models\User;
use App\Responser\JsonResponser;
use App\Services\Sms\VansoSMS;
use App\Services\ThirdParty\Termii\TermiiSMSService;
use App\Services\UserVerification\UserVerificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

trait VerificationCodeTrait
{
  protected $userVerificationService;

  public function __construct(UserVerificationService $userVerificationService)
  {
    $this->userVerificationService = $userVerificationService;
  }

  public function generateVerificationCode(
    User $user,
    string $modelable_type,
    int $modelable_id,
    ?bool $resend = false,
    ?string $identifier_type = IdentifierTypeEnums::EMAIL->value
  ) {

    if ($resend) {
      $this->resendVerificationCode($user->id, $modelable_type, $modelable_id);
    }

    $currentTime = date('Y-m-d H:i:s');
    $expiresAt = strtotime("+5 minutes", strtotime($currentTime));
    $expireFormatted = date('Y-m-d H:i:s', $expiresAt);

    $otp = GeneralHelper::getModelUniqueRandomId([
      'modelNamespace' => 'App\Models\UserVerification',
      'modelField' => 'code',
      "idLength" => 6,
    ]);

    $otp = "123456"; //to be removed after deployment //TODO

    $record = $this->userVerificationService->create([
      'identifier' => $identifier_type == IdentifierTypeEnums::EMAIL->value ? $user->email : $user->phoneno,
      'identifier_type' => $identifier_type,
      'code' => $otp,
      'expires_at' => $expireFormatted,
      'user_id' => $user->id,
      'modelable_type' => $modelable_type,
      'modelable_id' => $modelable_id
    ]);

    // $data = [
    //   'phonenumber' => '+234' . $user->phoneno,
    //   'message' => "Your one-time password (OTP) verification code is: " . $otp,
    // ];

    //EMAIL
    $emailData = [
      'user' => $user,
      'otp' => $otp,
      'otpMinutes' => $expiresAt,
    ];

    Mail::to($user->email)->send(new ForgotPasswordMail($emailData));

    //SMS
    $smsresponse = TermiiSMSService::sendOtpNotification($user->phoneno, $otp, $expiresAt);
    if (isset($smsresponse['error'])) {
      // DB::rollBack();
      // return JsonResponser::send(true, 'Failed to send OTP. Please try again.', null, 400);
    };

    $dataToLog = [
      'causer_id' => $user->id,
      'action_id' => $record->id,
      'action_type' => "App\Models\UserVerification",
      'log_name' => "Verification code generated successfully",
      'description' => "{$user['firstname']} {$user['lastname']} generated a verification code",
    ];

    GeneralHelper::storeAuditLog($dataToLog);
  }

  public function confirmVerificationCode(string $modelable_type, int $modelable_id, string $code)
  {
    $currentUser = auth()->user();
    $verification = $this->userVerificationService->findByMultiAttributes(
      [
        'code' => $code,
        'modelable_id' => $modelable_id,
        'modelable_type' => $modelable_type
      ]
    );

    if (!$verification) {
      throw new BadRequestException("Invalid code");
    }

    if (Carbon::parse($verification['expires_at']) < now()) {
      $verification->update([
        'status' => VerificationCodeStatusEnums::EXPIRED->value,
      ]);
      throw new BadRequestException("Code expired");
    }

    $verification->update([
      'verified_at' => now(),
      'status' => VerificationCodeStatusEnums::VERIFIED->value,
    ]);

    if ($currentUser) {
      $dataToLog = [
        'causer_id' => $currentUser->id,
        'action_id' => $verification->id,
        'action_type' => "App\Models\UserVerification",
        'log_name' => "Member verification code generated successfully",
        'description' => "{$currentUser['firstname']} {$currentUser['lastname']} verification code confirmed successfully",
      ];

      GeneralHelper::storeAuditLog($dataToLog);
    }
  }

  protected function resendVerificationCode($userId, $modelableType, $modelableId)
  {
    $verification = $this->userVerificationService->findByMultiAttributes(
      [
        'user_id' => $userId,
        'modelable_id' => $modelableId,
        'modelable_type' => $modelableType
      ]
    );

    if (!$verification) {
      throw new BadRequestException("User not found");
    }

    if ($verification['status'] == VerificationCodeStatusEnums::PENDING->value) {
      $verification->update([
        'status' => VerificationCodeStatusEnums::RESENT->value,
      ]);
    }
  }

  public function generateNewUserVerificationCode(
    User $user,
    string $modelable_type,
    int $modelable_id,
    ?bool $resend = false,
    ?string $identifier_type = IdentifierTypeEnums::EMAIL->value
  ) {
    // Handle resend logic
    if ($resend) {
      $this->resendVerificationCode($user->id, $modelable_type, $modelable_id);
    }

    // Generate expiration time
    $currentTime = date('Y-m-d H:i:s');
    $expiresAt = strtotime("+1440 minutes", strtotime($currentTime));
    $expireFormatted = date('Y-m-d H:i:s', $expiresAt);

    // Generate a unique token
    $token = GeneralHelper::getModelUniqueRandomId([
      'modelNamespace' => 'App\Models\UserVerification',
      'modelField' => 'code',
      "idLength" => 30,
      "idType" => "numalpha",
    ]);

    // Create a new verification record
    $record = $this->userVerificationService->create([
      'identifier' => $identifier_type === IdentifierTypeEnums::EMAIL->value ? $user->email : $user->phoneno,
      'identifier_type' => $identifier_type,
      'code' => $token,
      'expires_at' => $expireFormatted,
      'user_id' => $user->id,
      'modelable_type' => $modelable_type,
      'modelable_id' => $modelable_id
    ]);

    // Prepare email data
    $emailData = [
      'user' => $user,
      'verificationUrl' => $token,
      'otpMinutes' => $expireFormatted,
    ];

    // Send email based on user's role
    if ($user->user_type === 'familymember') {
      Mail::to($user->email)->send(new RelativeCreatePasswordMail($emailData));
    } else {
      Mail::to($user->email)->send(new CreatePasswordMail($emailData));
    }

    // Optional SMS notification (commented out for reference)
    // $data = [
    //   'phonenumber' => '+234' . $user->phoneno,
    //   'message' => "Your one-time password (OTP) verification code is: " . $token,
    // ];
    // $smsresponse = TermiiSMSService::sendOtpNotification($user->phoneno, $token, $expiresAt);
    // if (isset($smsresponse['error'])) {
    //     DB::rollBack();
    //     return JsonResponser::send(true, 'Failed to send OTP. Please try again.', null, 400);
    // }

    // Log the verification code generation
    $dataToLog = [
      'causer_id' => $user->id,
      'action_id' => $record->id,
      'action_type' => "App\Models\UserVerification",
      'log_name' => "Verification code generated successfully",
      'description' => "{$user['firstname']} {$user['lastname']} generated a verification code",
    ];

    GeneralHelper::storeAuditLog($dataToLog);
  }


  public function generateRegistrationCode(
    User $user,
    string $modelable_type,
    int $modelable_id,
    ?bool $resend = false,
    ?string $identifier_type = IdentifierTypeEnums::EMAIL->value,
    User $icobaAlumni = null, //if relative registation, add main icoba alumni
    ?string $relationship = null,
  ) {

    if ($resend) {
      $this->resendRegistrationCode($user->id, $modelable_type, $modelable_id);
    }

    $currentTime = date('Y-m-d H:i:s');
    $expiresAt = strtotime("+10 minutes", strtotime($currentTime));
    $expireFormatted = date('Y-m-d H:i:s', $expiresAt);

    $token = GeneralHelper::getModelUniqueRandomId([
      'modelNamespace' => 'App\Models\UserVerification',
      'modelField' => 'code',
      "idLength" => 30,
      "idType" => "numalpha",
    ]);

    $record = $this->userVerificationService->create([
      'identifier' => $identifier_type == IdentifierTypeEnums::EMAIL->value ? $user->email : $user->phoneno,
      'identifier_type' => $identifier_type,
      'code' => $token,
      'expires_at' => $expireFormatted,
      'user_id' => $user->id,
      'modelable_type' => $modelable_type,
      'modelable_id' => $modelable_id
    ]);

    $emailData = [
      'user' => $user,
      'verificationUrl' => $token,
      'icobaAlumni' => $icobaAlumni,
      'relationship' => $relationship,
    ];

    if ($icobaAlumni) {
      Mail::to($user->email)->send(new CreatePasswordMail($emailData));
    } else {
      Mail::to($user->email)->send(new CreatePasswordMail($emailData));
    }

    $dataToLog = [
      'causer_id' => $user->id,
      'action_id' => $record['id'],
      'action_type' => "App\Models\UserVerification",
      'log_name' => "Member verification code generated successfully",
      'description' => "{$user['firstname']} {$user['lastname']} generated a verification code",
    ];

    GeneralHelper::storeAuditLog($dataToLog);
  }

  protected function resendRegistrationCode($userId, $modelableType, $modelableId)
  {
    $verification = $this->userVerificationService->findByMultiAttributes(
      [
        'user_id' => $userId,
        'modelable_id' => $modelableId,
        'modelable_type' => $modelableType
      ]
    );

    if (!$verification) {
      throw new BadRequestException("User not found");
    }

    if ($verification['status'] == VerificationCodeStatusEnums::PENDING->value) {
      $verification->update([
        'status' => VerificationCodeStatusEnums::RESENT->value,
      ]);
    }
  }


  public function generateVotingVerificationCode(
    User $user,
    string $modelable_type,
    int $modelable_id,
    ?bool $resend = false,
    ?string $identifier_type = IdentifierTypeEnums::EMAIL->value
  ) {

    if ($resend) {
      $this->resendVerificationCode($user->id, $modelable_type, $modelable_id);
    }

    $currentTime = date('Y-m-d H:i:s');
    $expiresAt = strtotime("+5 minutes", strtotime($currentTime));
    $expireFormatted = date('Y-m-d H:i:s', $expiresAt);

    $otp = GeneralHelper::getModelUniqueRandomId([
      'modelNamespace' => 'App\Models\UserVerification',
      'modelField' => 'code',
      "idLength" => 6,
    ]);

    $otp = "123456";

    $record = $this->userVerificationService->create([
      'identifier' => $identifier_type == IdentifierTypeEnums::EMAIL->value ? $user->email : $user->phoneno,
      'identifier_type' => $identifier_type,
      'code' => $otp,
      'expires_at' => $expireFormatted,
      'user_id' => $user->id,
      'modelable_type' => $modelable_type,
      'modelable_id' => $modelable_id
    ]);

    // $data = [
    //   'phonenumber' => '+234' . $user->phoneno,
    //   'message' => "Your one-time password (OTP) verification code is: " . $otp,
    // ];

    //EMAIL
    $emailData = [
      'user' => $user,
      'otp' => $otp,
      'otpMinutes' => $expiresAt,
    ];

    Mail::to($user->email)->send(new VotingMail($emailData));

    //SMS
    $smsresponse = TermiiSMSService::sendOtpNotification($user->phoneno, $otp, $otpMinutes);
    if (isset($smsresponse['error'])) {
      // DB::rollBack();
      // return JsonResponser::send(true, 'Failed to send OTP. Please try again.', null, 400);
    };

    $dataToLog = [
      'causer_id' => $user->id,
      'action_id' => $record->id,
      'action_type' => "App\Models\UserVerification",
      'log_name' => "Verification code generated successfully",
      'description' => "{$user['firstname']} {$user['lastname']} generated a verification code",
    ];

    GeneralHelper::storeAuditLog($dataToLog);
  }

  public function generateVerificationCode2FA(
    User $user,
    string $modelable_type,
    int $modelable_id,
    ?bool $resend = false,
    ?string $identifier_type = IdentifierTypeEnums::EMAIL->value
  ) {

    if ($resend) {
      $this->resendVerificationCode($user->id, $modelable_type, $modelable_id);
    }

    $currentTime = date('Y-m-d H:i:s');
    $expiresAt = strtotime("+5 minutes", strtotime($currentTime));
    $expireFormatted = date('Y-m-d H:i:s', $expiresAt);

    $otp = GeneralHelper::getModelUniqueRandomId([
      'modelNamespace' => 'App\Models\UserVerification',
      'modelField' => 'code',
      "idLength" => 6,
    ]);

    $otp = "123456"; //to be removed after deployment //TODO

    $record = $this->userVerificationService->create([
      'identifier' => $identifier_type == IdentifierTypeEnums::EMAIL->value ? $user->email : $user->phoneno,
      'identifier_type' => $identifier_type,
      'code' => $otp,
      'expires_at' => $expireFormatted,
      'user_id' => $user->id,
      'modelable_type' => $modelable_type,
      'modelable_id' => $modelable_id
    ]);

    // $data = [
    //   'phonenumber' => '+234' . $user->phoneno,
    //   'message' => "Your one-time password (OTP) verification code is: " . $otp,
    // ];

    //EMAIL
    $emailData = [
      'user' => $user,
      'otp' => $otp,
      'otpMinutes' => $expiresAt,
    ];

    Mail::to($user->email)->send(new ForgotPasswordMail($emailData));

    //SMS
    $smsresponse = TermiiSMSService::sendOtpNotification($user->phoneno, $otp, $expiresAt);
    if (isset($smsresponse['error'])) {
      // DB::rollBack();
      // return JsonResponser::send(true, 'Failed to send OTP. Please try again.', null, 400);
    };

    $dataToLog = [
      'causer_id' => $user->id,
      'action_id' => $record->id,
      'action_type' => "App\Models\UserVerification",
      'log_name' => "Verification code generated successfully",
      'description' => "{$user['firstname']} {$user['lastname']} generated a verification code",
    ];

    GeneralHelper::storeAuditLog($dataToLog);
  }
}
