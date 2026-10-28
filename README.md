# gsoft-ai-wc-assistant

Assistente alla vendita AI per WooCommerce: una **piattaforma SaaS** (Laravel + MariaDB) che raccoglie gli eventi dei siti, costruisce il profilo di ogni cliente (cross-device) e serve raccomandazioni/chat, piu' un **plugin WooCommerce** da distribuire.

```
/platform   Laravel 11 (API, worker, dashboard)
/plugin     plugin WordPress/WooCommerce (plugin/gsoft-ai-assistant)
```

## Stato

- **Fase 1** (piattaforma): scheletro Laravel, tabelle tenant/identita', tenant scope (`BelongsToSite`), API firmata HMAC, comando per registrare i siti.
- **Fase 2** (ingest + plugin): endpoint `events`, `identify`, `catalog/sync` con stitching delle identita' e idempotenza; plugin con cookie `gsai_vid`, consenso (WP Consent API), tracker server-side, coda locale (Action Scheduler), sync catalogo e import ordini storici, pagina impostazioni in WooCommerce > AI Assistant.
- Prossime: Fase 3 (profili + raccomandazioni + widget), Fase 4 (chat), Fase 5 (dashboard, export/cancellazione dati, packaging).

Nulla e' ancora stato eseguito: il codice e' scritto "a mano" e verra' validato dalla CI al primo push.

## Plugin

Copia `plugin/gsoft-ai-assistant` in `wp-content/plugins` (richiede WooCommerce, PHP 8.0+), poi WooCommerce > AI Assistant: URL piattaforma, site key e secret. Il tracciamento parte solo dopo il consenso del visitatore (categoria `marketing` via WP Consent API; senza un plugin di consenso compatibile non viene tracciato nulla, salvo modalita' "Always allowed"). Staff con `manage_woocommerce` esclusi (filtro `gsai_ignore_user`). Hook utili: `gsai_track_event`, `gsai_identify_email` (newsletter), `gsai_tracking_allowed`, `gsai_consent_category`, `gsai_product_payload`, `gsai_event`.

## API firmata

Ogni richiesta del plugin invia:

| Header | Valore |
|---|---|
| `X-GSAI-Key` | `site_key` del sito |
| `X-GSAI-Timestamp` | unix time (tolleranza `GSAI_SIGNATURE_TOLERANCE`, default 300s) |
| `X-GSAI-Nonce` | stringa univoca per richiesta (anti-replay) |
| `X-GSAI-Signature` | `hex(hmac_sha256(site_secret, "{timestamp}.{nonce}.{body}"))` |

| Endpoint | Scopo |
|---|---|
| `GET /api/v1/ping` | test connessione |
| `POST /api/v1/events` | batch fino a 100 eventi, idempotenti su `event_id` |
| `POST /api/v1/identify` | collega cookie `anon_id` a `wc_user_id` / `email_sha256` (+ consensi) |
| `POST /api/v1/catalog/sync` | upsert fino a 100 prodotti + elenco `deleted` |

## Deploy su Plesk (Laravel Toolkit)

Requisiti server: PHP >= 8.2 (`pdo_mysql`, `mbstring`, `openssl`), MariaDB 10.6+ (quella gia' presente su Plesk; crea database e utente dedicati). Gli embedding della Fase 3 non richiedono estensioni: vengono salvati su colonna JSON e la similarita' e' calcolata in PHP sui candidati.

1. Plesk > Laravel > aggiungi applicazione da Git, **document root `platform/public`**, directory applicazione `platform`.
2. Imposta le variabili ambiente copiando `platform/.env.example` (genera `APP_KEY` dal Toolkit o con `php artisan key:generate`; imposta `GSAI_EMAIL_SALT` con un valore casuale e **non cambiarlo piu'**).
3. Esegui `composer install --no-dev --optimize-autoloader` e `php artisan migrate --force` dal Toolkit.
4. Attiva lo **scheduler** (cron `php artisan schedule:run` ogni minuto) e un **queue worker** (`php artisan queue:work --tries=3`) dal Toolkit. Si usa la coda `database`: Redis e' opzionale.
5. Registra un sito: `php artisan gsai:site:create "Nome" dominio.it` e inserisci site key/secret nel plugin.

## Test

I test girano in CI (GitHub Actions, `.github/workflows/ci.yml`) con SQLite in memoria; in locale: `cd platform && composer install && php artisan test`.
