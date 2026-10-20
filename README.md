# VATSIM Quarterly ATC Activity

PHP version for `https://vqaa.jurina.org`.

## Files

```text
project/
├─ lib.php
├─ callsign-data.php
├─ vatsim.sqlite
├─ tools/
│  ├─ build-callsign-data.php
│  └─ vnas_facilities.json
├─ .env
├─ .env.example
├─ .gitignore
└─ public/
   ├─ index.html
   ├─ app-v4.js
   ├─ site-v4.css
   ├─ api.php
   ├─ auth.php
   └─ .htaccess
```

## Baota deployment

Set the website root to:

```text
project/public
```

Enable PHP with cURL and sessions. No Node.js, PM2, or SQL database is required.
Callsign lookup data lives in `callsign-data.php` (a plain PHP array), so `pdo_sqlite` is **not** required at runtime. It is generated from `vatsim.sqlite` (17,809 airports and 1,235 FIR records from the VATSpy data project) and `tools/vnas_facilities.json` (the VATUSA vNAS facility tree: every ARTCC, TRACON, tower and position prefix). After updating either source, rebuild it locally with:

```bash
php tools/build-callsign-data.php
```

`lib.php` stays one level above `public` and contains the server-side VATSIM API and classification logic. The public PHP files call it with `require_once ../lib.php`.

## Environment

Create `.env` in the project root:

```env
VATSIM_CLIENT_ID=your-sandbox-client-id
VATSIM_CLIENT_SECRET=your-sandbox-client-secret
VATSIM_REDIRECT_URI=https://vqaa.jurina.org/auth/callback
VATSIM_CA_BUNDLE=C:\php\extras\ssl\cacert.pem
```

Register this exact redirect URI in the VATSIM Connect Sandbox OAuth client:

```text
https://vqaa.jurina.org/auth.php?action=callback
```

Both callback URLs are supported:

```text
https://vqaa.jurina.org/auth.php?action=callback
https://vqaa.jurina.org/auth/callback
```

The second URL is a compatibility endpoint for the OAuth client you already registered.

The `.env` file must remain outside `public`. The included `.htaccess` also disables directory listing and blocks sensitive files.

If Windows PHP reports `unable to get local issuer certificate`, download `https://curl.se/ca/cacert.pem`, save it outside `public`, and set `VATSIM_CA_BUNDLE` to its full path. SSL certificate verification remains enabled.

## Endpoints

```text
GET /api.php?cid=1749073
GET /auth.php?action=login
GET /auth.php?action=callback&code=...
GET /auth.php?action=status
GET /auth.php?action=logout
```

ATC history is fetched server-side from the VATSIM API. Classification and quarter aggregation stay on the server; the browser receives only the calculated summary.

Every callsign is grouped by its real VATSIM Division (VATUSA, VATPRC, VATEUD, VATUK, VATCAN, VATJPN, VATSEA, VATRUS, VATMENA …) and an area (ARTCC for VATUSA, FIR for VATCAN/VATPRC/VATUK, vACC for VATEUD, country for the rest). Resolution order in `classify_callsign()`:

1. Special callsigns (`NAT`, `ZAK`, VATUK/VATPAC TMA names such as `ESSEX_APP`, `SY_APP`).
2. VATUSA vNAS facilities and positions (`MEM_22_CTR`, `HSV_E_APP`, `L30_APP`, `HNL_02_CTR`, `GUM_R5_CTR`).
3. Airport identifier: ICAO (`ZSPD_TWR`, `KLAS_TWR`), FAA LID (`LAS_TWR` → KLAS, `FYV_GND` → KFYV), IATA (`YYZ_TWR` → CYYZ).
4. VATSpy FIR/sector prefix matched on whole callsign segments (`EDGG_CTR`, `ZSHA_CTR`, `LON_S_CTR`, `TOR_CTR`, `BN-TSN_FSS`). `_CTR`/`_FSS` callsigns try this before step 3, so `MCN_CTR` is Moncton while `MCN_TWR` is Macon.
5. ICAO nationality letters for airfields VATSpy does not list (`ZSXX_TWR` → VATPRC).

Only airspace without a Division (shared NAT oceanic, Mongolia, North Korea, Antarctica, Falklands) or a callsign matching nothing is shown under *Other divisions*.

The result is an estimate and may be incomplete or inaccurate. It is not an official VATSIM compliance decision.

## Optional Nginx compatibility rewrite

If the OAuth client is already registered with `/auth/callback`, add this rule to the site's Nginx configuration, then reload Nginx:

```nginx
location = /auth/callback {
    rewrite ^/auth/callback$ /auth.php?action=callback&$query_string last;
}
```

The direct PHP redirect URI is:

```text
https://vqaa.jurina.org/auth.php?action=callback
```
