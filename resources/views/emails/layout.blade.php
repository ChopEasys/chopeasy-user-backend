<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>@yield('title', 'ChopEasy')</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#222;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">@yield('preheader', 'A message from ChopEasy')</div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:32px 12px;">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;">
                    <tr>
                        <td style="padding:23px 30px;background:#222222;border-bottom:4px solid #ffc107;">
                            <div style="font-size:25px;font-weight:800;letter-spacing:-.5px;color:#ffffff;">Chop<span style="color:#ffc107;">Easy</span></div>
                            <div style="margin-top:5px;font-size:12px;letter-spacing:.7px;color:#d1d5db;">SMART FOOD SAVINGS</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:34px 30px 30px;line-height:1.65;font-size:15px;color:#374151;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 30px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.6;color:#6b7280;">
                            <p style="margin:0 0 6px;">Need help? Contact the ChopEasy support team.</p>
                            <p style="margin:0;">&copy; {{ date('Y') }} ChopEasy. All rights reserved.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>