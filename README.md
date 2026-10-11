# VATSIM Quarterly ATC Activity

PHP version for `https://vqaa.jurina.org`.

## Files

```text
project/
├─ lib.php
├─ vatsim.sqlite
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
Enable `pdo_sqlite` for local airport/FIR lookup. The included `vatsim.sqlite` contains 17,809 airports and 1,235 FIR records imported from the VATSpy data project. It is a local SQLite file, not a MySQL service.

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

Airport-style local callsigns are classified separately from Division prefixes. For example, `FYV_GND` is treated as KFYV / Drake Field and grouped under VATUSA / Memphis ARTCC. X-Plane Custom Data can confirm airport identifiers and coordinates, but it is not a complete VATSIM ARTCC-boundary database; the deployed PHP app therefore uses a server-side airport/facility map rather than a local `D:\SteamLibrary` path.

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
