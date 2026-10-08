<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }} - Clientèle Group</title>
</head>
<body style="margin:0;background:#f4f5f8;color:#0e0e10;font-family:Arial,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:18px;overflow:hidden;">
                    <tr>
                        <td style="padding:24px 28px;background:#0e0e10;color:#ffffff;">
                            <strong style="font-size:18px;letter-spacing:.03em;">CLIENTÈLE GROUP</strong>
                            <span style="display:block;margin-top:6px;color:#ffc4c4;font-size:12px;font-weight:700;letter-spacing:.08em;">CLIENTÈLE CAR RENTAL</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px 28px;">
                            <p style="margin:0 0 14px;font-size:16px;line-height:1.5;">Bonjour {{ $recipientName }},</p>
                            <h1 style="margin:0 0 14px;color:#0e0e10;font-size:24px;line-height:1.25;">{{ $heading }}</h1>
                            <p style="margin:0 0 20px;font-size:16px;line-height:1.5;">{{ $intro }}</p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;border-radius:12px;background:#eef0ff;color:#2222e6;">
                                <tr>
                                    <td style="padding:16px 18px;">
                                        <strong style="display:block;font-size:12px;letter-spacing:.08em;">RÉSERVATION</strong>
                                        <span style="display:block;margin-top:5px;font-size:22px;font-weight:800;letter-spacing:.05em;">{{ $reservationNumber }}</span>
                                    </td>
                                </tr>
                            </table>
                            @if ($vehicleImageUrl)
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;border:1px solid #e4e6ed;border-radius:12px;overflow:hidden;">
                                    <tr>
                                        <td align="center" style="padding:16px 16px 8px;">
                                            <img src="{{ $vehicleImageUrl }}" alt="{{ $vehicleImageAlt }}" width="240" style="display:block;width:100%;max-width:240px;height:auto;border:0;" />
                                        </td>
                                    </tr>
                                    <tr>
                                        <td align="center" style="padding:0 16px 14px;color:#6b6d78;font-size:12px;line-height:1.4;">Illustration de catégorie, pas une photo du véhicule</td>
                                    </tr>
                                </table>
                            @endif
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
                                @foreach ($details as $label => $value)
                                    <tr>
                                        <td style="padding:8px 0;border-top:1px solid #e4e6ed;color:#6b6d78;font-size:13px;line-height:1.4;">{{ $label }}</td>
                                        <td style="padding:8px 0 8px 14px;border-top:1px solid #e4e6ed;color:#24252d;font-size:13px;font-weight:700;line-height:1.4;text-align:right;">{{ $value }}</td>
                                    </tr>
                                @endforeach
                            </table>
                            @if (count($pdfAttachments) > 0)
                                <p style="margin:20px 0 0;font-size:14px;line-height:1.5;color:#565866;">Les documents PDF disponibles sont joints à ce courriel.</p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px;background:#f8f8fa;color:#6b6d78;font-size:12px;line-height:1.5;">
                            Pour toute question, contactez Clientèle Car Rental. Ne transmettez jamais vos informations personnelles par réponse à ce courriel.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
