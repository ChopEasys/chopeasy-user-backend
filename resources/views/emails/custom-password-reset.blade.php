<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Password Reset Request</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f4f4;
            padding: 30px;
        }

        .container {
            max-width: 600px;
            margin: auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        h2 {
            color: #333;
        }

        .btn {
            display: inline-block;
            padding: 12px 24px;
            background-color: #3490dc;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
            margin-top: 20px;
        }

        .footer {
            font-size: 14px;
            color: #6c757d;
            margin-top: 30px;
            border-top: 1px solid #e9ecef;
            padding-top: 15px;
        }
    </style>
</head>

<body>
    <div class="container">
        <h2>Hello {{ $user->firstname ?? 'there' }},</h2>
        <p>You requested a password reset. Click the button below to reset your password:</p>

        <a href="https://chopeasy.ng/auth/reset-password?token={{ urlencode($token) }}&email={{ urlencode($user->email) }}" class="btn">
            Reset Password
        </a>


        <p>If you didn’t request this, you can safely ignore this email.</p>

        <div class="footer">
            Regards,<br>
         ChopEasy Team
        </div>
    </div>
</body>

</html>