<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Code de sécurité Clientèle Group</title>
</head>
<body style="margin:0;background:#f4f5f8;color:#0e0e10;font-family:Arial,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:18px;overflow:hidden;">
                    <tr>
                        <td style="padding:24px 28px;background:#0e0e10;color:#ffffff;">
                            <strong style="font-size:18px;letter-spacing:.03em;">CLIENTÈLE GROUP</strong>
                            <span style="display:block;margin-top:6px;color:#ffc4c4;font-size:12px;font-weight:700;letter-spacing:.08em;">SÉCURITÉ DU COMPTE</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px 28px;">
                            <p style="margin:0 0 14px;font-size:16px;line-height:1.5;">Bonjour {{ $recipientName }},</p>
                            <p style="margin:0 0 22px;font-size:16px;line-height:1.5;">
                                Utilisez ce code pour {{ $purposeLabel }}. Ne le partagez avec personne.
                            </p>
                            <p style="margin:0 0 22px;padding:18px;border-radius:12px;background:#eef0ff;color:#2222e6;font-size:32px;font-weight:800;letter-spacing:.22em;text-align:center;">
                                {{ $code }}
                            </p>
                            <p style="margin:0;font-size:14px;line-height:1.5;color:#565866;">
                                Ce code expire à {{ $expiresAt->setTimezone('America/Port-au-Prince')->format('d/m/Y h:i A') }} (Cap-Haïtien, Haïti) et ne peut être utilisé qu’une seule fois.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px;background:#f8f8fa;color:#6b6d78;font-size:12px;line-height:1.5;">
                            Si vous n’êtes pas à l’origine de cette demande, ignorez ce courriel et avisez l’administrateur Clientèle Group.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
