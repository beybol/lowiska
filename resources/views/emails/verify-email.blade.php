<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Verify e-mail address') }}</title>
</head>

<body
    style="font-family: Arial, sans-serif; background-color: #f9fafb; padding: 20px; color: #111827;"
>
    <table
        align="center"
        width="100%"
        style="max-width: 600px; background: white; border-radius: 8px; padding: 24px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);"
    >
        <tr>
            <td style="text-align: center;">
                <h1
                    style="color: #f59e0b; font-size: 24px; margin-bottom: 16px;"
                >
                    {{ __('Verify e-mail address') }}
                </h1>

                <p
                    style="font-size: 16px; color: #374151; margin-bottom: 24px;"
                >
                    {{ __('Thank you for registering! Click the button below to verify your email address.') }}
                </p>

                <a
                    href="{{ $url }}"
                    style="display: inline-block; background-color: #f59e0b; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold;"
                >
                    {{ __('Verify e-mail address') }}
                </a>

                <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
                    {{ __('If you did not create an account, no further action is required.') }}
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
