<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Courriel d’essai Clientèle Group</title>
</head>
<body style="margin:0;background:#f4f5f8;color:#0e0e10;font-family:Arial,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:18px;overflow:hidden;">
                    <tr>
                        <td style="padding:24px 28px;background:#0e0e10;color:#ffffff;">
                            <strong style="font-size:18px;letter-spacing:.03em;">CLIENTÈLE GROUP</strong>
                            <span style="display:block;margin-top:6px;color:#ffc4c4;font-size:12px;font-weight:700;letter-spacing:.08em;">SERVEUR DE COURRIEL</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px 28px;">
                            <p style="margin:0 0 14px;font-size:16px;line-height:1.5;">Bonjour {{ $recipientName }},</p>
                            <p style="margin:0 0 14px;font-size:16px;line-height:1.5;">
                                Ce courriel d’essai confirme que le serveur de courriel de Clientèle Group ERP envoie les messages.
                            </p>
                            <p style="margin:0;font-size:14px;line-height:1.5;color:#565866;">
                                Envoyé le {{ $sentAt->copy()->setTimezone('America/Port-au-Prince')->format('d/m/Y h:i A') }} (Cap-Haïtien, Haïti)@if ($fromAddress) depuis {{ $fromAddress }}@endif.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px;background:#f8f8fa;color:#6b6d78;font-size:12px;line-height:1.5;">
                            Envoyé depuis Configuration à votre demande. Aucune action n’est nécessaire.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
