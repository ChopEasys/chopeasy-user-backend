<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Your OTP Code</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            color: #212529;
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 600px;
            margin: 30px auto;
            padding: 30px;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        h2 {
            color: #343a40;
        }

        p {
            font-size: 16px;
            line-height: 1.6;
            margin: 15px 0;
        }

        .otp-code {
            display: inline-block;
            padding: 15px 30px;
            background-color: #28a745;
            color: #ffffff;
            font-size: 24px;
            font-weight: bold;
            border-radius: 6px;
            margin: 20px 0;
            letter-spacing: 2px;
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
        <h2>Hello {{ $user->firstname }} {{ $user->lastname }},</h2>

        <p> Please use the OTP below to verify your email address. It will expire in 10 minutes.</p>

        <div class="otp-code">{{ $otp }}</div>

        <p>If you did not request this code, you can safely ignore this email.</p>

        <div class="footer">
            Regards,<br>
            ChopEasy Ltd
        </div>
    </div>
</body>

</html>