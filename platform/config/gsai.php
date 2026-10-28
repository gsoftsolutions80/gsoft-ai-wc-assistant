<?php

return [
    // Secondi di tolleranza tra timestamp della richiesta firmata e ora del server.
    'signature_tolerance' => (int) env('GSAI_SIGNATURE_TOLERANCE', 300),

    // Richieste al minuto per singolo sito.
    'rate_limit_per_minute' => (int) env('GSAI_RATE_LIMIT_PER_MINUTE', 600),

    // Sale globale per l'hash SHA-256 delle email (identita' cross-sito).
    'email_salt' => env('GSAI_EMAIL_SALT'),
];
