#!/usr/bin/env bash
# Idempotent Blesta hosting packages for Falcon of Codes homepage + order flow.
# Creates/updates: Free Trial ($0), Starter ($5), Maker ($9), Pro ($13).
# Assigns the "Trial Site Access" config-option group to every hosting package.
# Emits blesta_packages.json (provisioner + homepage packages.json).
#
# Run AFTER setup_blesta_options.sh (needs the option group). Safe to re-run.
set -uo pipefail
DB="${FALCON_BLESTA_DB:-falc_blesta}"
COMPANY=1
MODULE=1
PKG_GROUP=2
ORDER_FORM=1
OPT_GROUP_NAME="Trial Site Access"
MAP="/opt/falcon-provisioner/templates/blesta_packages.json"
WEB_MAP="/home/falcon.codes/public_html/packages.json"
REPO_MAP="$(dirname "$0")/../../falcon-codes/packages.json"

q(){ mysql "$DB" -N -B -e "$1"; }

next_pkg_id_value(){ q "SELECT COALESCE(MAX(id_value),0)+1 FROM packages WHERE company_id=$COMPANY;"; }

ensure_package(){
  local key="$1" name="$2" price="$3" html="$4" force_id="${5:-}"
  local pkg_id=""
  if [ -n "$force_id" ]; then
    pkg_id=$(q "SELECT id FROM packages WHERE id=$force_id AND company_id=$COMPANY LIMIT 1;")
  fi
  if [ -z "$pkg_id" ]; then
    pkg_id=$(q "SELECT pn.package_id FROM package_names pn
                JOIN packages p ON p.id=pn.package_id
                WHERE pn.lang='en_us' AND pn.name='$name' AND p.company_id=$COMPANY LIMIT 1;")
  fi
  if [ -z "$pkg_id" ]; then
    local idv; idv=$(next_pkg_id_value)
    mysql "$DB" -e "INSERT INTO packages (id_format,id_value,module_id,qty,client_qty,module_row,manual_activation,taxable,single_term,status,hidden,company_id,override_price)
      VALUES ('{num}',$idv,$MODULE,NULL,NULL,0,0,0,0,'active',0,$COMPANY,0);"
    pkg_id=$(q "SELECT id FROM packages WHERE company_id=$COMPANY AND id_value=$idv LIMIT 1;")
    q "INSERT INTO package_names (package_id,lang,name) VALUES ($pkg_id,'en_us','$name');"
    q "INSERT INTO package_descriptions (package_id,lang,html,text) VALUES ($pkg_id,'en_us','$html','');"
    echo "  created package id=$pkg_id name=$name"
  else
    q "UPDATE package_names SET name='$name' WHERE package_id=$pkg_id AND lang='en_us';"
    q "UPDATE package_descriptions SET html='$html' WHERE package_id=$pkg_id AND lang='en_us';"
    echo "  updated package id=$pkg_id name=$name"
  fi

  # pricing row (1 month)
  local pp_id="" pr_id=""
  pp_id=$(q "SELECT pp.id FROM package_pricing pp JOIN pricings pr ON pr.id=pp.pricing_id
             WHERE pp.package_id=$pkg_id AND pr.period='month' AND pr.term=1 LIMIT 1;")
  if [ -z "$pp_id" ]; then
    local new_pr
    new_pr=$(mysql "$DB" -N -B -e "INSERT INTO pricings (company_id,term,period,price,setup_fee,cancel_fee,currency)
      VALUES ($COMPANY,1,'month',$price,0,0,'USD'); SELECT LAST_INSERT_ID();")
    q "INSERT INTO package_pricing (package_id,pricing_id,\`default\`) VALUES ($pkg_id,$new_pr,1);"
    pp_id=$(q "SELECT id FROM package_pricing WHERE package_id=$pkg_id ORDER BY id DESC LIMIT 1;")
    echo "    pricing package_pricing_id=$pp_id price=\$$price"
  else
    pr_id=$(q "SELECT pricing_id FROM package_pricing WHERE id=$pp_id;")
    q "UPDATE pricings SET price=$price WHERE id=$pr_id;"
    echo "    pricing package_pricing_id=$pp_id price=\$$price (updated)"
  fi

  # link to hosting package group
  q "INSERT IGNORE INTO package_group (package_group_id,package_id) VALUES ($PKG_GROUP,$pkg_id);"

  # assign config option group (if it exists)
  local ogid; ogid=$(q "SELECT id FROM package_option_groups WHERE company_id=$COMPANY AND name='$OPT_GROUP_NAME' LIMIT 1;")
  if [ -n "$ogid" ]; then
    q "INSERT IGNORE INTO package_option (package_id,option_group_id,\`order\`) VALUES ($pkg_id,$ogid,0);"
  else
    echo "  WARN: option group '$OPT_GROUP_NAME' not found — run setup_blesta_options.sh first"
  fi

  eval "PKG_${key}_ID=$pkg_id"
  eval "PP_${key}_ID=$pp_id"
}

echo "=== Falcon hosting packages (idempotent) ==="

# Rename package group for clarity
q "UPDATE package_group_names SET name='Falcon Hosting' WHERE package_group_id=$PKG_GROUP AND lang='en_us';"

ensure_package trial "Free Trial" "0.0000" \
  "<p>24-hour subdomain trial. Pick a theme, launch instantly — upgrade anytime.</p>" 4
ensure_package starter "Starter" "5.0000" \
  "<p>One polished site on your <code>name.falcon.codes</code> subdomain. All themes, HTTPS, basic analytics.</p>"
ensure_package maker "Maker" "9.0000" \
  "<p>Connect your own custom domain. All themes, contact forms, remove the Falcon badge.</p>"
ensure_package pro "Pro" "13.0000" \
  "<p>For growing businesses: up to 3 custom domains, priority publishing, SEO tools, email support.</p>"

# Ensure trial package (legacy id 4) is linked even if renamed
q "INSERT IGNORE INTO package_group (package_group_id,package_id) VALUES ($PKG_GROUP,4);"

# Resolve IDs (fallback to pkg 4 for trial if name lookup differed)
PKG_trial_ID="${PKG_trial_ID:-4}"
PP_trial_ID="${PP_trial_ID:-$(q "SELECT id FROM package_pricing WHERE package_id=${PKG_trial_ID} LIMIT 1;")}"

# Config option ids for order prefill (package_options.id, NOT option_pricing_id)
OPT_SUB=$(q "SELECT id FROM package_options WHERE company_id=$COMPANY AND name='subdomain' LIMIT 1;")
OPT_THEME=$(q "SELECT id FROM package_options WHERE company_id=$COMPANY AND name='theme' LIMIT 1;")
OF_LABEL=$(q "SELECT label FROM order_forms WHERE id=$ORDER_FORM;")

mkdir -p "$(dirname "$MAP")"
cat > "$MAP" <<JSON
{
  "order_form": "$OF_LABEL",
  "group_id": $PKG_GROUP,
  "config_options": {
    "subdomain": ${OPT_SUB:-67},
    "theme": ${OPT_THEME:-68}
  },
  "packages": {
    "trial": {
      "package_id": ${PKG_trial_ID},
      "pricing_id": ${PP_trial_ID},
      "price": 0,
      "name": "Free Trial"
    },
    "starter": {
      "package_id": ${PKG_starter_ID},
      "pricing_id": ${PP_starter_ID},
      "price": 5,
      "name": "Starter"
    },
    "maker": {
      "package_id": ${PKG_maker_ID},
      "pricing_id": ${PP_maker_ID},
      "price": 9,
      "name": "Maker"
    },
    "pro": {
      "package_id": ${PKG_pro_ID},
      "pricing_id": ${PP_pro_ID},
      "price": 13,
      "name": "Pro"
    }
  }
}
JSON
chmod 644 "$MAP"
echo "=== wrote $MAP ==="
cat "$MAP"

if [ -d "$(dirname "$WEB_MAP")" ]; then
  cp "$MAP" "$WEB_MAP"
  chown falco6812:falco6812 "$WEB_MAP" 2>/dev/null || true
  echo "=== copied to $WEB_MAP ==="
fi

if [ -d "$(dirname "$REPO_MAP")" ]; then
  cp "$MAP" "$REPO_MAP" 2>/dev/null || true
fi

echo "=== verify ==="
q "SELECT p.id, pn.name, pp.id AS package_pricing_id, pr.price
   FROM packages p
   JOIN package_names pn ON pn.package_id=p.id AND pn.lang='en_us'
   JOIN package_group pg ON pg.package_id=p.id AND pg.package_group_id=$PKG_GROUP
   JOIN package_pricing pp ON pp.package_id=p.id
   JOIN pricings pr ON pr.id=pp.pricing_id
   WHERE p.company_id=$COMPANY ORDER BY pr.price, p.id;"
