==============================================================================
 Falcon of Codes — Manual WordPress Provisioner (PROOF OF CONCEPT)
 Reference run: demo-test.falcon.codes   (created 2026-07-18)
==============================================================================

PURPOSE
  Document the exact, repeatable manual steps used to stand up a clean
  WordPress site on a child (sub)domain of falcon.codes, with SSL and a free
  wordpress.org theme, using CyberPanel + WP-CLI.

  This is a MANUAL proof only. It is intentionally NOT wired to Blesta and does
  NOT auto-provision on order. Do not add Softaculous.

------------------------------------------------------------------------------
ENVIRONMENT (as observed on this server)
------------------------------------------------------------------------------
  Control panel : CyberPanel (OpenLiteSpeed)
  Master domain : falcon.codes   (owner user: falco6812, CyberPanel acct: admin)
  PHP           : lsphp83 (PHP 8.3.x) with ionCube; CLI php 8.3
  WP-CLI        : /usr/local/bin/wp   (v2.12.0)
  DNS           : falcon.codes is hosted at GoDaddy (ns03/ns04.domaincontrol.com).
                  A WILDCARD record *.falcon.codes -> 195.35.1.18 already exists,
                  so any new sub.falcon.codes resolves to this box immediately.
                  => Let's Encrypt HTTP-01 works with no manual DNS edit.
                  If DNS were NOT wildcard, you'd first add an A record for the
                  subdomain (at GoDaddy, or here if DNS were local) before SSL.

------------------------------------------------------------------------------
VARIABLES USED FOR THE REFERENCE RUN
------------------------------------------------------------------------------
  MASTER=falcon.codes
  CHILD=demo-test.falcon.codes
  DOC=/home/falcon.codes/demo-test.falcon.codes   # isolated, OUTSIDE public_html
  WPDB=falc_wpdemo   WPDBUSER=falc_wpdemo
  Credentials stored root-only (chmod 600):
    /root/.wpdemo_db_credentials      (WP database user/password)
    /root/.wpdemo_admin_credentials   (WP admin user/password/URL)

------------------------------------------------------------------------------
STEP 1 — Create the child domain + issue SSL (CyberPanel CLI)
------------------------------------------------------------------------------
  NOTE: the CLI function is "createChild" (NOT "createChildDomain").
  --path is relative to /home/<masterDomain>/ . Using the bare child name
  keeps the docroot OUTSIDE the master's public_html (cleaner isolation).

  cyberpanel createChild \
    --masterDomain falcon.codes \
    --childDomain demo-test.falcon.codes \
    --owner admin \
    --php 8.3 \
    --ssl 1 \
    --dkim 0 \
    --openBasedir 0 \
    --path demo-test.falcon.codes

  Expected result: {"success": 1, "errorMessage": "None"}
    - Creates vhost /usr/local/lsws/conf/vhosts/demo-test.falcon.codes/vhost.conf
    - docRoot -> /home/falcon.codes/demo-test.falcon.codes (default index.html)
    - Issues Let's Encrypt cert -> /etc/letsencrypt/live/demo-test.falcon.codes/
    (A benign log line "Websites matching query does not exist
     [installSSLForDomain]" may appear for child domains; the cert is still
     issued and wired into the vhost vhssl block.)

  Verify:
    curl -sI http://demo-test.falcon.codes/     # 200
    curl -sI https://demo-test.falcon.codes/    # 200, valid cert (no -k needed)

------------------------------------------------------------------------------
STEP 2 — Install WP-CLI (one-time, already done on this server)
------------------------------------------------------------------------------
  curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    -o /usr/local/bin/wp
  chmod +x /usr/local/bin/wp
  wp --version --allow-root

------------------------------------------------------------------------------
STEP 3 — Create a dedicated MySQL/MariaDB database + user
------------------------------------------------------------------------------
  WPDBPASS=$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-24)
  mysql -e "CREATE DATABASE IF NOT EXISTS falc_wpdemo
            CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql -e "CREATE USER IF NOT EXISTS 'falc_wpdemo'@'localhost'
            IDENTIFIED BY '${WPDBPASS}';"
  mysql -e "GRANT ALL PRIVILEGES ON falc_wpdemo.* TO 'falc_wpdemo'@'localhost';
            FLUSH PRIVILEGES;"
  # Save creds root-only:
  printf 'DB_HOST=localhost\nDB_NAME=falc_wpdemo\nDB_USER=falc_wpdemo\nDB_PASS=%s\n' \
    "$WPDBPASS" > /root/.wpdemo_db_credentials
  chmod 600 /root/.wpdemo_db_credentials

------------------------------------------------------------------------------
STEP 4 — Install WordPress (run as the SITE USER for correct ownership)
------------------------------------------------------------------------------
  IMPORTANT: run wp as falco6812 (NOT root) so files are owned by the site user.
  Use the ABSOLUTE path /usr/local/bin/wp (sudo resets PATH).

  DOC=/home/falcon.codes/demo-test.falcon.codes
  rm -f "$DOC/index.html"          # remove CyberPanel default placeholder

  sudo -u falco6812 -H /usr/local/bin/wp core download --path="$DOC"

  sudo -u falco6812 -H /usr/local/bin/wp config create --path="$DOC" \
    --dbname=falc_wpdemo --dbuser=falc_wpdemo --dbpass='<from /root/.wpdemo_db_credentials>' \
    --dbhost=localhost

  sudo -u falco6812 -H /usr/local/bin/wp core install --path="$DOC" \
    --url="https://demo-test.falcon.codes" \
    --title="Falcon Demo Test" \
    --admin_user="falconadmin" \
    --admin_password='<generated; saved to /root/.wpdemo_admin_credentials>' \
    --admin_email="mzughbo@gmail.com" \
    --skip-email

------------------------------------------------------------------------------
STEP 5 — Install + activate a free wordpress.org theme
------------------------------------------------------------------------------
  sudo -u falco6812 -H /usr/local/bin/wp theme install twentytwentyfour \
    --activate --path="$DOC"
  sudo -u falco6812 -H /usr/local/bin/wp theme list --status=active --path="$DOC"

------------------------------------------------------------------------------
STEP 6 — Verify
------------------------------------------------------------------------------
  curl -sI https://demo-test.falcon.codes/            # HTTP/2 200, server LiteSpeed
  curl -s  https://demo-test.falcon.codes/ | grep generator   # WordPress 7.0.2
  https://demo-test.falcon.codes/wp-admin/            # 302 -> wp-login (normal)
  Admin login: https://demo-test.falcon.codes/wp-admin/  (creds in
               /root/.wpdemo_admin_credentials)

------------------------------------------------------------------------------
REFERENCE RUN RESULT (2026-07-18)
------------------------------------------------------------------------------
  URL         : https://demo-test.falcon.codes/   -> HTTP 200 (valid SSL)
  WordPress   : 7.0.2
  Theme       : Twenty Twenty-Four (active)
  Docroot     : /home/falcon.codes/demo-test.falcon.codes  (owner falco6812)
  DB          : falc_wpdemo / falc_wpdemo @ localhost
  Admin user  : falconadmin  (password in /root/.wpdemo_admin_credentials)

------------------------------------------------------------------------------
TEARDOWN (for reference only — do NOT run unless retiring the demo)
------------------------------------------------------------------------------
  cyberpanel deleteChild --childDomain demo-test.falcon.codes
  mysql -e "DROP DATABASE IF EXISTS falc_wpdemo;"
  mysql -e "DROP USER IF EXISTS 'falc_wpdemo'@'localhost';"
  rm -f /root/.wpdemo_db_credentials /root/.wpdemo_admin_credentials

==============================================================================
 NEXT (NOT done yet — out of scope for this phase):
   - Auto-provision on Blesta order (custom provisioner module)
   - Multiple theme templates / template_id mapping from homepage
   - Domain registrar API integration
==============================================================================
