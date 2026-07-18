==============================================================================
 Falcon of Codes - Phase 4 Automated WordPress Provisioner
 falcon.codes homepage  ->  subdomain reservation  ->  Blesta $0 trial
                         ->  automated WordPress on CyberPanel/OpenLiteSpeed
==============================================================================

Location of this file: /opt/falcon-provisioner/README.txt
Last updated: 2026-07-18 (Phase 4b: auto-activate + per-site creds in client area)

------------------------------------------------------------------------------
0. WHAT THIS IS
------------------------------------------------------------------------------
A self-contained pipeline that lets a visitor on https://falcon.codes:
  1. pick a free subdomain (name.falcon.codes) and have it checked/reserved,
  2. pick a theme,
  3. complete a $0 "Trial" order in Blesta,
after which a clean WordPress site is provisioned automatically on a child
domain of falcon.codes (SSL included) with the chosen theme, and the client is
notified with the site URL + wp-admin credentials.

NO Softaculous. Provisioning uses CyberPanel CLI + WP-CLI only.

------------------------------------------------------------------------------
0b. PHASE 4b - FULLY AUTOMATIC ACTIVATION + PER-SITE CREDENTIALS
------------------------------------------------------------------------------
Goal: a completed $0 order needs ZERO staff clicks, and the client sees their
site + login credentials in the Blesta client area and by email.

(A) AUTO-ACTIVATE (no admin interruption)
    A $0 Trial order completes with the service ACTIVE immediately - no manual
    review, staff approval, pending hold, fraud check, or email verification.
    Enforced idempotently by:  bin/setup_blesta_options.sh
    Blesta UI paths + the DB keys it sets (company_id 1):
      - Order form "Trial Signup" (Packages > Order Forms, form id 1):
          order_forms.manual_review   = 0
          order_forms.require_captcha = 0
          order_forms.require_tos     = 0
      - Package "Trial placeholder" (Packages > Browse, pkg id 4):
          packages.manual_activation  = 0     (Welcome/Provision: auto)
      - Settings > Company > ... (company_settings):
          email_verification          = false
          prevent_unverified_payments = false
      - Order antifraud (Settings > Company > Plugins > Order):
          order_settings.antifraud    = (removed / none, so no MaxMind)
    The every-minute cron poller provisions any ACTIVE package-4 service.

(B) PER-SITE ACCOUNT = pure-ftpd VIRTUAL FTP/FTPS USER (least privilege)
    Each provisioned site gets its own FTP/FTPS account, chrooted to the site
    docroot and mapped to the unprivileged system uid/gid falco6812 (5003).
    It is NEVER root and NEVER the CyberPanel admin login.
    Why not a per-customer CyberPanel :8090 panel login?  CyberPanel does not
    offer a real single-child-domain panel login for customers, and its
    `createFTPAccount` CLI is broken for child domains
    ("ChildDomains object has no attribute 'package'"). So we write the virtual
    user directly into cyberpanel.users - the SAME MySQL backend CyberPanel's
    own pure-ftpd uses (MYSQLCrypt=crypt; password via `openssl passwd -6`).
    users.domain_id must reference the MASTER website id (websiteFunctions_
    websites.id for falcon.codes) or the FK is rejected.
      Login URL for this account type: FTP/FTPS (explicit TLS) to
      <sub>.falcon.codes : 21   (username = <sub>)
    Secrets stored root-only in secrets/<sub>.env (FTP_USER/FTP_PASS/...).

(C) CREDENTIALS IN THE BLESTA CLIENT AREA
    Shown at:  Client area > Services > (the service) > "Configurable Options".
    Implemented with a hidden config-option group ("Trial Site Access") assigned
    to package 4; the poller UPSERTs service_options rows on provision. No Blesta
    theme edit, no custom module. Fields shown: Subdomain, Theme, Website URL,
    WordPress Admin URL/Username/Password, File Access (FTP/FTPS)/Username/
    Password. Same creds are also emailed on provision.
    Field -> option_pricing_id map: templates/blesta_options.json
      (subdomain, theme, site_url, wp_admin_url, wp_user, wp_pass,
       ftp_access, ftp_user, ftp_pass)

(D) CUSTOM ORDER FIELDS (bulletproof matching)
    `subdomain` and `theme` are config options on package 4. The poller reads
    them from the service's service_options as the PRIMARY mapping source
    (logged as "[source=blesta_field]"); bridge.json (token/email/recency) is
    the FALLBACK. Homepage carries subdomain/theme/token in the order URL and
    also posts them to the bridge API.

------------------------------------------------------------------------------
1. ARCHITECTURE / DATA FLOW
------------------------------------------------------------------------------
 [Homepage JS]                                     https://falcon.codes/
   |  (a) GET  /api/subdomain/check?name=NAME  --> {available}
   |  (b) POST /api/subdomain/reserve name=NAME --> {reservation_token, expires}
   |         (45-min hold written to reservations.json)
   |  (c) POST /api/subdomain/bridge {token,subdomain,theme}
   |         (carrier record written to bridge.json)
   |  (d) redirect ->
   |     https://falcon.codes/portal/order/?subdomain=X&theme=T&token=TK
   v
 [Blesta $0 Trial order]  (order form slug "trial", package id 4, $0 / 1 month)
   |  creates a client + an ACTIVE service on package 4
   v
 [Poller]  /opt/falcon-provisioner/bin/blesta_poll.php   (cron, every minute)
   |  - finds new active package-4 services not yet provisioned (sites.json)
   |  - resolves {subdomain,theme} from bridge.json (token/email/recency)
   |  - calls provision.sh --subdomain X --theme T --email E --client_id C --token TK
   v
 [Provisioner]  /opt/falcon-provisioner/bin/provision.sh   (root)
   |  1. validate slug + token
   |  2. cyberpanel createChild ... (child vhost + Let's Encrypt SSL)
   |  3. create MariaDB db + user
   |  4. wp core download/config/install  (as falco6812)
   |  5. wp theme install <slug> --activate  (fallback twentytwentyfour)
   |  6. ensure SSL
   |  7. append record to sites.json, remove reservation, mark bridge claimed
   |  8. write temp admin password to root-only sites secret file
   v
 https://X.falcon.codes/  (live WordPress, HTTP 200, valid SSL)

------------------------------------------------------------------------------
2. URL / TOKEN CONTRACT (must stay consistent frontend <-> backend)
------------------------------------------------------------------------------
Endpoints (served by OpenLiteSpeed as PHP, running as user falco6812):
  GET  https://falcon.codes/api/subdomain/check?name=NAME
  POST https://falcon.codes/api/subdomain/reserve      body: name=NAME
  POST https://falcon.codes/api/subdomain/bridge       body: token,subdomain,theme[,email]

Slug rule (authoritative, enforced in _lib.php AND provision.sh):
  ^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])$   (3-32 chars)
Reserved words (rejected):
  www portal mail api admin demo-test ns1 ns2 ftp cpanel webmail mx smtp
  imap pop root blesta falcon static cdn assets

Reservation token: 32 hex chars (16 random bytes). TTL 45 minutes (2700 s).

Order redirect URL (built by homepage script.js):
  https://falcon.codes/portal/order/?subdomain=<sub>&theme=<theme_id>&token=<token>
sessionStorage keys (reliable fallback in the browser):
  falcon_subdomain, falcon_theme, falcon_token

theme_id values come from homepage theme cards (data-theme):
  studio, cafe, saas, portfolio, realestate, agency
mapped to WordPress theme slugs via templates/meta.json.

------------------------------------------------------------------------------
3. FILE LOCATIONS
------------------------------------------------------------------------------
Code / templates (root-owned):
  /opt/falcon-provisioner/README.txt          this file
  /opt/falcon-provisioner/bin/provision.sh     core provisioner (root)
  /opt/falcon-provisioner/bin/blesta_poll.php  cron poller (root)
  /opt/falcon-provisioner/templates/meta.json  theme_id -> wp slug map
  /srv/falcon-docs/provisioner.md              short operator doc

Web API (served by OLS, owner falco6812):
  /home/falcon.codes/public_html/api/subdomain/_lib.php
  /home/falcon.codes/public_html/api/subdomain/check.php
  /home/falcon.codes/public_html/api/subdomain/reserve.php
  /home/falcon.codes/public_html/api/subdomain/bridge.php
  /home/falcon.codes/public_html/api/subdomain/.htaccess

State (NOT web-served; /var/lib/falcon-provisioner is 2750 falco6812:falco6812):
  /var/lib/falcon-provisioner/reservations.json  written by API (falco6812)
  /var/lib/falcon-provisioner/bridge.json        written by API (falco6812)
  /var/lib/falcon-provisioner/sites.json         written by provisioner (root)
  /var/lib/falcon-provisioner/secrets/<sub>.env  temp wp admin pw (root 600)

Logs / cron:
  /var/log/falcon-provisioner.log              all provisioner + poller steps
  /etc/cron.d/falcon-provisioner               runs poller every minute

Secrets (root only, do NOT web-serve, do NOT revoke the api key):
  /root/.blesta_api_key            API_USER / API_KEY
  /root/.blesta_admin_credentials  Blesta admin login

------------------------------------------------------------------------------
4. ENVIRONMENT (as observed on this server)
------------------------------------------------------------------------------
  Control panel : CyberPanel (OpenLiteSpeed)
  Master domain : falcon.codes  (owner user: falco6812, CyberPanel acct: admin)
  PHP           : lsphp83 (PHP 8.3.x) + ionCube; CLI php 8.3
  WP-CLI        : /usr/local/bin/wp  (v2.12.0)
  DNS           : GoDaddy, WILDCARD *.falcon.codes -> 195.35.1.18, so any new
                  sub resolves immediately => Let's Encrypt HTTP-01 works with
                  no manual DNS edit.
  Blesta        : 5.13.10 at /home/falcon.codes/public_html/portal/
                  DB falc_blesta (root socket). Product "Trial placeholder"
                  package id 4 (group id 2), pricing id 34 = 1 month @ $0 USD,
                  order form id 1 slug "trial".

  IMPORTANT: run wp as the site user with the absolute path (sudo resets PATH):
    sudo -u falco6812 -H /usr/local/bin/wp <cmd> --path=<docroot>

------------------------------------------------------------------------------
5. MANUAL WORDPRESS RUN (reference: demo-test.falcon.codes, 2026-07-18)
------------------------------------------------------------------------------
This is exactly what provision.sh automates. Kept as the canonical reference.

STEP 1 - child domain + SSL (CyberPanel CLI; function is createChild):
  cyberpanel createChild \
    --masterDomain falcon.codes \
    --childDomain demo-test.falcon.codes \
    --owner admin --php 8.3 --ssl 1 --dkim 0 --openBasedir 0 \
    --path demo-test.falcon.codes
  Expected: {"success": 1}. docRoot -> /home/falcon.codes/demo-test.falcon.codes
  (a benign "Websites matching query does not exist [installSSLForDomain]" log
   can appear; the Let's Encrypt cert is still issued and wired into vhssl.)

STEP 2 - DB + user:
  WPDBPASS=$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-24)
  mysql -e "CREATE DATABASE IF NOT EXISTS falc_wpdemo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql -e "CREATE USER IF NOT EXISTS 'falc_wpdemo'@'localhost' IDENTIFIED BY '${WPDBPASS}';"
  mysql -e "GRANT ALL PRIVILEGES ON falc_wpdemo.* TO 'falc_wpdemo'@'localhost'; FLUSH PRIVILEGES;"

STEP 3 - WordPress (run as falco6812, absolute wp path):
  DOC=/home/falcon.codes/demo-test.falcon.codes
  rm -f "$DOC/index.html"     # remove CyberPanel placeholder
  sudo -u falco6812 -H /usr/local/bin/wp core download --path="$DOC"
  sudo -u falco6812 -H /usr/local/bin/wp config create --path="$DOC" \
    --dbname=falc_wpdemo --dbuser=falc_wpdemo --dbpass='<pw>' --dbhost=localhost
  sudo -u falco6812 -H /usr/local/bin/wp core install --path="$DOC" \
    --url="https://demo-test.falcon.codes" --title="Falcon Demo Test" \
    --admin_user="falconadmin" --admin_password='<gen>' \
    --admin_email="mzughbo@gmail.com" --skip-email

STEP 4 - theme:
  sudo -u falco6812 -H /usr/local/bin/wp theme install twentytwentyfour \
    --activate --path="$DOC"

STEP 5 - verify:
  curl -sI https://demo-test.falcon.codes/          # HTTP/2 200
  curl -s  https://demo-test.falcon.codes/ | grep generator   # WordPress x.y.z

Reference-run creds are root-only in /root/.wpdemo_db_credentials and
/root/.wpdemo_admin_credentials. Leave demo-test.falcon.codes as the reference.

------------------------------------------------------------------------------
6. USING THE PROVISIONER DIRECTLY (manual / admin)
------------------------------------------------------------------------------
  /opt/falcon-provisioner/bin/provision.sh \
    --subdomain mycafe --theme cafe --email owner@example.com \
    --client_id 3 [--token <reservation_token>] [--force]

Outputs the site URL + wp-admin URL + temp admin user; the temp password is
written to /var/lib/falcon-provisioner/secrets/<sub>.env (root 600) and echoed
once in the log. Idempotent: if the site dir already exists it skips creation
unless --force.

------------------------------------------------------------------------------
7. BLESTA INTEGRATION (poller)
------------------------------------------------------------------------------
Design choice: a cron poller (not a Blesta module) reads the falc_blesta DB
directly for new ACTIVE services on package 4 that are not yet in sites.json,
resolves the {subdomain,theme} from bridge.json, and runs provision.sh.

Why the bridge: Blesta's stock order form does not reliably persist arbitrary
query params (subdomain/theme/token) into the order record. The homepage
therefore POSTs {token,subdomain,theme} to /api/subdomain/bridge before
redirecting to the order form. The poller matches a new service to a bridge
entry by, in order: (1) token (if carried through), (2) client email,
(3) most-recent unclaimed bridge entry inside the reservation window.
Limitation: under heavy concurrent signups with no token/email match, (3) is
best-effort. For guaranteed mapping, add two Blesta custom order fields
(subdomain, theme) to the trial order form and read service_fields; documented
but not enabled by default to keep the trial form frictionless.

Trigger cadence: /etc/cron.d/falcon-provisioner runs the poller every minute.

------------------------------------------------------------------------------
8. DEMO HOLD vs TRIAL LENGTH
------------------------------------------------------------------------------
- "Demo hold" = the 45-minute pre-order subdomain reservation (reservations.json).
  It only prevents two visitors from grabbing the same name during checkout.
- Trial length = the Blesta service term: package 4 = 1 month @ $0. The live
  site's lifecycle follows the Blesta service. Automatic suspend/cancel of the
  WordPress site when the Blesta service is suspended/cancelled is OUT OF SCOPE
  for this phase (documented as a future add-on).

------------------------------------------------------------------------------
9. ROLLBACK / TEARDOWN
------------------------------------------------------------------------------
Tear down ONE provisioned site:
  cyberpanel deleteChild --childDomain <sub>.falcon.codes
  mysql -e "DROP DATABASE IF EXISTS <dbname>;"
  mysql -e "DROP USER IF EXISTS '<dbuser>'@'localhost';"
  # then remove its record from /var/lib/falcon-provisioner/sites.json
  rm -f /var/lib/falcon-provisioner/secrets/<sub>.env

Disable the whole pipeline:
  rm -f /etc/cron.d/falcon-provisioner        # stop auto-provisioning
  # (optional) remove the API dir to stop reservations:
  #   rm -rf /home/falcon.codes/public_html/api/subdomain
Roll back a homepage edit (backups made on every deploy):
  cp -a /home/falcon.codes/public_html/index.html.bak.<TS> \
        /home/falcon.codes/public_html/index.html
  chown falco6812:falco6812 /home/falcon.codes/public_html/index.html

------------------------------------------------------------------------------
10. HARD CONSTRAINTS (respect these)
------------------------------------------------------------------------------
  - Softaculous: NOT installed, must NOT be installed.
  - Do NOT touch /srv/clientexec/* or portal.clientexec.disabled.
  - Do NOT change the Blesta "falcon" client theme/branding.
  - Do NOT revoke /root/.blesta_api_key.
  - Keep secrets out of web-served locations (state under /var/lib, temp
    passwords root-only).
==============================================================================
