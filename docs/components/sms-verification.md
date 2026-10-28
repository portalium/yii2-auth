# SMS phone verification

SMS verification is an authenticated, optional profile flow. It does not change signup or login.

## Request a code

`POST /api/auth/defaults/request-sms-verification`

```json
{
  "tel_no": "+905311234567"
}
```

The phone number must use E.164 format. A code is valid for five minutes. Requests for another
code are limited to one per user every 60 seconds and five per hour. A number already assigned to
another account does not receive a message; the response intentionally does not disclose that
account's existence.

The SMS provider's `accepted` status means it accepted the request, not that the handset received
the message. If delivery status is unknown, the generated code remains eligible for verification.

## Verify a code

`POST /api/auth/defaults/verify-sms-verification`

```json
{
  "code": "012345"
}
```

The code must be sent as a string to preserve leading zeroes. After successful verification, the
phone is saved to the signed-in user's profile and `sms_verified` is set to `10`. The challenge is
consumed and its code cannot be reused. Five incorrect attempts invalidate outstanding codes and
lock verification for 15 minutes. Resending a code does not reset the attempt count.

Until verification succeeds, a newly requested number is kept only in the challenge record; it
does not replace the user's existing phone number.
