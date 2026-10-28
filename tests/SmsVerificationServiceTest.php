<?php

namespace portalium\auth\tests;

require_once dirname(__DIR__, 3) . '/autoload.php';
require_once dirname(__DIR__, 3) . '/yiisoft/yii2/Yii.php';

use PHPUnit\Framework\TestCase;
use portalium\auth\components\SmsVerificationService;
use portalium\auth\models\SmsVerification;
use portalium\user\models\User;
use Yii;
use yii\base\Component;
use yii\console\Application;
use yii\db\Connection;

class SmsVerificationServiceTest extends TestCase
{
    private $application;
    private $notification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->application = new Application([
            'id' => 'auth-sms-verification-test',
            'basePath' => dirname(__DIR__),
            'components' => [
                'db' => [
                    'class' => Connection::class,
                    'dsn' => 'sqlite::memory:',
                ],
                'security' => [
                    'class' => \yii\base\Security::class,
                ],
                'user' => [
                    'class' => \yii\web\User::class,
                    'identityClass' => User::class,
                    'enableSession' => false,
                ],
            ],
        ]);

        Yii::$app->db->createCommand(
            'CREATE TABLE user_user (
                id_user INTEGER PRIMARY KEY,
                tel_no VARCHAR(16) NULL UNIQUE,
                sms_verified INTEGER NOT NULL DEFAULT 20,
                email_verify INTEGER NOT NULL DEFAULT 20,
                date_create DATETIME NULL,
                date_update DATETIME NULL
            )'
        )->execute();
        Yii::$app->db->createCommand(
            'CREATE TABLE auth_sms_verification (
                id_user INTEGER PRIMARY KEY,
                active_phone VARCHAR(16) NULL,
                active_code_hash VARCHAR(255) NULL,
                active_expires_at INTEGER NULL,
                pending_phone VARCHAR(16) NULL,
                pending_code_hash VARCHAR(255) NULL,
                pending_expires_at INTEGER NULL,
                last_sent_at INTEGER NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                locked_until INTEGER NULL,
                window_started_at INTEGER NULL,
                sent_count INTEGER NOT NULL DEFAULT 0
            )'
        )->execute();

        Yii::$app->set('notification', ['class' => SmsVerificationTestNotification::class]);
        $this->notification = Yii::$app->notification;
        $this->createUser(1);
        Yii::$app->user->setIdentity(User::findOne(1));
    }

    protected function tearDown(): void
    {
        if ($this->application) {
            $this->application->db->close();
            Yii::$app = null;
            $this->application = null;
        }

        parent::tearDown();
    }

    public function testPhoneIsSavedAsVerifiedOnlyAfterCodeIsConfirmed()
    {
        $user = User::findOne(1);
        $result = (new SmsVerificationService())->requestCode('+905311234567');

        $this->assertTrue($result['success']);
        $this->assertSame('pending', $result['status']);
        $this->assertNull($user->tel_no);
        $this->assertSame(User::SMS_NOT_VERIFY, (int) $user->sms_verified);
        $this->assertArrayNotHasKey('code', $result);

        $code = $this->codeFromSentMessage();
        $verificationResult = (new SmsVerificationService())->verifyCode($code);
        $verifiedUser = User::findOne(1);

        $this->assertTrue($verificationResult['success']);
        $this->assertSame('+905311234567', $verifiedUser->tel_no);
        $this->assertSame(User::SMS_VERIFY, (int) $verifiedUser->sms_verified);
        $verification = SmsVerification::findOne(1);
        $this->assertNull($verification->active_code_hash);
        $this->assertNull($verification->pending_code_hash);
    }

    public function testUserVerificationHelperRejectsMalformedPhoneNumbers()
    {
        $user = User::findOne(1);

        $this->assertFalse($user->verifySmsPhone('not-a-phone-number'));
        $this->assertNull($user->tel_no);
        $this->assertSame(User::SMS_NOT_VERIFY, (int) $user->sms_verified);
    }

    public function testEmailMustBeVerifiedBeforeRequestingSmsVerification()
    {
        Yii::$app->user->identity->email_verify = User::EMAIL_NOT_VERIFY;

        $result = (new SmsVerificationService())->requestCode('+905311234567');

        $this->assertFalse($result['success']);
        $this->assertSame('email_not_verified', $result['error']);
        $this->assertCount(0, $this->notification->messages);
    }

    public function testSmsVerificationModelValidatesFieldsAndUserRelation()
    {
        $verification = new SmsVerification([
            'id_user' => 1,
            'active_phone' => '+905311234567',
            'active_code_hash' => str_repeat('a', 60),
            'active_expires_at' => time() + 300,
            'last_sent_at' => time(),
            'attempts' => 0,
            'sent_count' => 1,
        ]);

        $this->assertTrue($verification->validate(), json_encode($verification->errors));

        $verification->attempts = -1;
        $this->assertFalse($verification->validate(['attempts']));
        $this->assertSame(User::class, $verification->getUser()->modelClass);
    }

    public function testUnknownSmsDeliveryKeepsThePossibleCodeVerifiable()
    {
        $this->notification->result = [
            'success' => false,
            'status' => 'unknown',
            'retryable' => false,
        ];
        $user = User::findOne(1);

        $result = (new SmsVerificationService())->requestCode('+905311234567');

        $this->assertTrue($result['success']);
        $this->assertSame('unknown', $result['status']);
        $this->assertSame(
            'verified',
            (new SmsVerificationService())->verifyCode($this->codeFromSentMessage())['status']
        );
    }

    public function testKnownSendFailureKeepsThePreviouslyAcceptedCodeValid()
    {
        $user = User::findOne(1);
        $service = new SmsVerificationService();
        $service->requestCode('+905311234567');
        $firstCode = $this->codeFromSentMessage();

        $verification = SmsVerification::findOne(1);
        $verification->last_sent_at = time() - SmsVerificationService::RESEND_INTERVAL - 1;
        $verification->save(false);
        $this->notification->result = [
            'success' => false,
            'status' => 'not_sent',
            'retryable' => false,
        ];

        $result = $service->requestCode('+905311234568');

        $this->assertSame('sms_not_sent', $result['error']);
        $this->assertNull(SmsVerification::findOne(1)->pending_code_hash);
        $this->assertSame(
            'verified',
            $service->verifyCode($firstCode)['status']
        );
        $this->assertSame('+905311234567', User::findOne(1)->tel_no);
    }

    public function testWrongCodesAreLimitedAndLockTheChallenge()
    {
        $user = User::findOne(1);
        (new SmsVerificationService())->requestCode('+905311234567');
        $sentCode = $this->codeFromSentMessage();
        $wrongCode = $this->differentCode([$sentCode]);
        $service = new SmsVerificationService();

        for ($attempt = 1; $attempt < SmsVerificationService::MAX_ATTEMPTS; $attempt++) {
            $result = $service->verifyCode($wrongCode);
            $this->assertSame('invalid_code', $result['error']);
        }

        $result = $service->verifyCode($wrongCode);
        $verification = SmsVerification::findOne(1);

        $this->assertSame('too_many_attempts', $result['error']);
        $this->assertNull($verification->active_code_hash);
        $this->assertGreaterThan(time(), (int) $verification->locked_until);
        $this->assertSame(
            'too_many_attempts',
            $service->requestCode('+905311234568')['error']
        );
    }

    public function testResendingDoesNotResetIncorrectAttemptCount()
    {
        $user = User::findOne(1);
        $service = new SmsVerificationService();
        $service->requestCode('+905311234567');
        $firstCode = $this->codeFromSentMessage();
        $wrongCode = $this->differentCode([$firstCode]);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $service->verifyCode($wrongCode);
        }

        $verification = SmsVerification::findOne(1);
        $verification->last_sent_at = time() - SmsVerificationService::RESEND_INTERVAL - 1;
        $verification->save(false);
        $this->notification->result = [
            'success' => true,
            'status' => 'accepted',
            'retryable' => false,
        ];
        $service->requestCode('+905311234568');

        $this->assertSame(3, (int) SmsVerification::findOne(1)->attempts);
        $nextCode = $this->codeFromSentMessage();
        $wrongCode = $this->differentCode([$nextCode]);
        $service->verifyCode($wrongCode);
        $result = $service->verifyCode($wrongCode);

        $this->assertSame('too_many_attempts', $result['error']);
        $this->assertGreaterThan(time(), (int) SmsVerification::findOne(1)->locked_until);
    }

    public function testResendCooldownPreventsAnotherSms()
    {
        $user = User::findOne(1);
        $service = new SmsVerificationService();
        $service->requestCode('+905311234567');

        $result = $service->requestCode('+905311234567');

        $this->assertFalse($result['success']);
        $this->assertSame('resend_too_soon', $result['error']);
        $this->assertCount(1, $this->notification->messages);
    }

    public function testSmsRequestsAreLimitedPerHour()
    {
        $user = User::findOne(1);
        $service = new SmsVerificationService();
        $service->requestCode('+905311234567');

        for ($request = 1; $request < SmsVerificationService::MAX_SMS_PER_WINDOW; $request++) {
            $verification = SmsVerification::findOne(1);
            $verification->last_sent_at = time() - SmsVerificationService::RESEND_INTERVAL - 1;
            $verification->save(false);
            $service->requestCode('+905311234567');
        }

        $this->assertCount(SmsVerificationService::MAX_SMS_PER_WINDOW, $this->notification->messages);
        $verification = SmsVerification::findOne(1);
        $verification->last_sent_at = time() - SmsVerificationService::RESEND_INTERVAL - 1;
        $verification->save(false);
        $result = $service->requestCode('+905311234567');

        $this->assertSame('sms_rate_limited', $result['error']);
        $this->assertCount(SmsVerificationService::MAX_SMS_PER_WINDOW, $this->notification->messages);
        $this->assertSame(
            'verified',
            $service->verifyCode($this->codeFromSentMessage())['status']
        );

        $verification = SmsVerification::findOne(1);
        $this->assertSame(SmsVerificationService::MAX_SMS_PER_WINDOW, (int) $verification->sent_count);
        $verification->last_sent_at = time() - SmsVerificationService::RESEND_INTERVAL - 1;
        $verification->save(false);
        $this->assertSame(
            'sms_rate_limited',
            $service->requestCode('+905311234568')['error']
        );

        $verification = SmsVerification::findOne(1);
        $verification->window_started_at = time() - SmsVerificationService::SMS_WINDOW - 1;
        $verification->save(false);
        $this->assertTrue($service->requestCode('+905311234568')['success']);
        $this->assertSame(1, (int) SmsVerification::findOne(1)->sent_count);
    }

    public function testNumberOwnedByAnotherUserDoesNotReceiveAnSms()
    {
        $this->createUser(2, '+905311234567');
        $user = User::findOne(1);

        $result = (new SmsVerificationService())->requestCode('+905311234567');

        $this->assertTrue($result['success']);
        $this->assertSame('pending', $result['status']);
        $this->assertCount(0, $this->notification->messages);
        $this->assertNull($user->tel_no);
    }

    private function createUser($id, $phone = null)
    {
        Yii::$app->db->createCommand()->insert('user_user', [
            'id_user' => $id,
            'tel_no' => $phone,
            'sms_verified' => User::SMS_NOT_VERIFY,
            'email_verify' => User::EMAIL_VERIFY,
        ])->execute();
    }

    private function codeFromSentMessage()
    {
        $message = end($this->notification->messages);
        $this->assertMatchesRegularExpression('/\b\d{6}\b/', $message);
        preg_match('/\b(\d{6})\b/', $message, $matches);

        return $matches[1];
    }

    private function differentCode(array $usedCodes)
    {
        for ($candidate = 0; $candidate <= 999999; $candidate++) {
            $code = sprintf('%06d', $candidate);
            if (!in_array($code, $usedCodes, true)) {
                return $code;
            }
        }

        $this->fail('Unable to generate a test code distinct from the used codes.');
    }
}

class SmsVerificationTestNotification extends Component
{
    public $result = [
        'success' => true,
        'status' => 'accepted',
        'retryable' => false,
    ];
    public $messages = [];

    public function sendSms($to, $message)
    {
        $this->messages[] = $message;

        return $this->result;
    }
}
