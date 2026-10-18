#!/usr/bin/env bash
#
# WorkSuite Server — one-command installer
#
#   curl -fsSL https://download.worksuitecloud.com/install.sh -o install.sh
#   sudo bash install.sh --license-key WS-XXXX-XXXX-XXXX-XXXX-XXXX --domain erp.example.com --admin-email you@example.com
#
# or, asking for anything not given:
#
#   curl -fsSL https://download.worksuitecloud.com/install.sh | sudo bash
#
# Copyright © Livezen Technologies. All Rights Reserved.
#
# Supported (fresh server, x86_64 or aarch64):
#   Ubuntu 22.04 / 24.04 LTS · Debian 12 · RHEL / Rocky / AlmaLinux 9 (CentOS Stream 9)
#   CentOS 7 — best effort: it is end of life, and PHP 8.3 / MariaDB 10.11 come from third-party repositories.
#
# What it does: checks the licence key with the WorkSuite Download Center, installs Nginx, PHP 8.3-FPM,
# MariaDB, Redis, Node.js 20 and Certbot, downloads the WorkSuite Server package for the licence's
# edition, verifies the release manifest's Ed25519 signature against the pinned WorkSuite release key
# and the package's SHA-256 against it, unpacks it into releases/<version>+<build> (files.sha256 checked),
# creates the database and .env, migrates, creates the first administrator, ACTIVATES THE LICENCE on this
# server, and installs the updater agent, background jobs, nightly backups, the firewall and TLS.
#
# It never deletes a database. Re-run with --repair to re-apply configuration to an existing install.

set -Eeuo pipefail
umask 022

INSTALLER_VERSION="2.0.0"

# ---------------------------------------------------------------------------
# Pinned: the WorkSuite Download Center and the release key that signs every release manifest
# (also served at https://download.worksuitecloud.com/api/v1/keys). Nothing unsigned is installed.
# ---------------------------------------------------------------------------
WS_DOWNLOAD_CENTER="${WS_DOWNLOAD_CENTER:-https://download.worksuitecloud.com}"
WS_RELEASE_KEY_ID="ws-release-1"
WS_RELEASE_KEY="4LoA_LfKb1KQ0rVJgzqxLodhS4OCXntsFvOck7lNpv0"

# ---------------------------------------------------------------------------
# Defaults
# ---------------------------------------------------------------------------
DOMAIN=""; ADMIN_EMAIL=""; ORG_NAME=""; LICENSE_KEY="${WORKSUITE_LICENSE_KEY:-}"; CHANNEL="stable"; TARGET_VERSION=""
PACKAGE=""; MANIFEST=""
INSTALL_DIR="/var/www/worksuite"; PHP_V="8.3"; NODE_MAJOR="20"
DB_HOST="localhost"; DB_PORT="3306"; DB_NAME="worksuite"; DB_USER="worksuite"; DB_PASS=""
INSTALL_TYPE="production"; FP_MODE="machine"; OFFLINE=0
EXTERNAL_DB=0; TIMEZONE=""; SSL=1; FIREWALL=1; WHATSAPP=1; REALTIME=1
NONINTERACTIVE=0; REPAIR=0; DRY_RUN=0; SKIP_PRECHECK=0
EDITION=""; REL_VERSION=""; REL_BUILD=""; REL_DIR=""; ADMIN_PASS=""; RAM_MB=0; WORK=""; PKG_SHA=""
SWAP_MB=2048

LOG=/var/log/worksuite-install.log
CRED_FILE=/root/worksuite-credentials.txt
DEPLOY_USER=worksuite-deploy
STEP=0; TOTAL_STEPS=17

# Set by detect_os(): the distribution family and everything that differs between them.
FAMILY=""; PKG=""; WEB_USER=""; PHP_BIN=""; FPM_SERVICE=""; FPM_CONF_DIR=""; FPM_POOL_DIR=""; FPM_SOCK=""
PHP_CLI_INI_DIR=""; NGINX_SITE=""; MARIADB_CNF=""; REDIS_SERVICE=""; CRON_SERVICE=""; LEGACY=0

if [[ -t 1 ]]; then B=$'\e[1m'; C=$'\e[36m'; G=$'\e[32m'; Y=$'\e[33m'; R=$'\e[31m'; N=$'\e[0m'; else B=; C=; G=; Y=; R=; N=; fi
log()  { printf '%s %s\n' "$(date -u +%FT%TZ)" "$*" >>"$LOG" 2>/dev/null || true; }
step() { STEP=$((STEP+1)); printf '\n%s[%d/%d]%s %s%s%s\n' "$C" "$STEP" "$TOTAL_STEPS" "$N" "$B" "$*" "$N"; log "STEP $STEP $*"; }
info() { printf '    %s\n' "$*"; log "INFO $*"; }
ok()   { printf '    %s✓%s %s\n' "$G" "$N" "$*"; log "OK $*"; }
warn() { printf '    %s!%s %s\n' "$Y" "$N" "$*" >&2; log "WARN $*"; }
die()  { printf '\n%s✗ %s%s\n' "$R" "$*" "$N" >&2; log "FAIL $*"; printf '  Log: %s\n' "$LOG" >&2; exit 1; }
run()  { log "RUN $*"; if [[ $DRY_RUN -eq 1 ]]; then info "(dry run) $*"; else "$@" >>"$LOG" 2>&1 || die "Failed: $* (see $LOG)"; fi; }
trap 'die "Stopped at line $LINENO."' ERR

usage() {
  cat <<EOF
WorkSuite Server installer $INSTALLER_VERSION

  sudo bash install.sh [options]

Required (asked for when missing, unless --non-interactive):
  --license-key KEY        WorkSuite licence key (WS-XXXX-…), from the WorkSuite Download Center.
                           Also read from \$WORKSUITE_LICENSE_KEY.
  --domain NAME            The address people will use, e.g. erp.example.com (DNS pointing here).
  --admin-email EMAIL      The first administrator's email (also used for Let's Encrypt).

Options:
  --org-name NAME          Organisation name (default: the domain).
  --channel stable|beta|lts   Release channel (default stable; lts needs a licence that includes it).
  --version X.Y.Z          A specific published version instead of the newest.
  --package FILE --manifest FILE   Install from a downloaded package and its signed manifest (no download).
  --offline                With --package/--manifest, for a server that cannot reach the WorkSuite Download
                           Center: no licence check online; the installer writes the offline activation
                           request (.wsr) at the end. System packages still come from your OS mirrors.
  --installation-type production|staging|dr   Staging and disaster-recovery servers use their own licence
                           limits, not a production slot (default production).
  --cluster                One installation on several nodes: the licence fingerprint leaves the machine out,
                           so all nodes share one activation (writable/ and the database must be shared).
  --install-dir DIR        Default /var/www/worksuite.
  --db-host H --db-port P --db-name N --db-user U --db-pass P   Use an existing MySQL/MariaDB server.
  --timezone ZONE          Default: the server's timezone.
  --no-ssl                 Skip Let's Encrypt (TLS terminated elsewhere).
  --no-firewall            Leave the firewall alone.
  --no-whatsapp            Skip the WhatsApp bridge.
  --no-realtime            Skip the realtime gateway.
  --non-interactive        Never ask; fail if something required is missing.
  --repair                 Re-apply configuration to an existing installation (data untouched).
  --dry-run                Show what would happen; change nothing.
  --skip-precheck          Continue on an unsupported OS or a small server (not recommended).
  -h, --help               This help.
EOF
}

parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --license-key) LICENSE_KEY="${2:-}"; shift 2;;
      --domain) DOMAIN="${2:-}"; shift 2;;
      --admin-email) ADMIN_EMAIL="${2:-}"; shift 2;;
      --org-name) ORG_NAME="${2:-}"; shift 2;;
      --channel) CHANNEL="${2:-}"; shift 2;;
      --version) TARGET_VERSION="${2:-}"; shift 2;;
      --package) PACKAGE="${2:-}"; shift 2;;
      --manifest) MANIFEST="${2:-}"; shift 2;;
      --install-dir) INSTALL_DIR="${2:-}"; shift 2;;
      --installation-type) INSTALL_TYPE="${2:-}"; shift 2;;
      --cluster) FP_MODE="cluster"; shift;;
      --offline) OFFLINE=1; shift;;
      --db-host) DB_HOST="${2:-}"; EXTERNAL_DB=1; shift 2;;
      --db-port) DB_PORT="${2:-}"; shift 2;;
      --db-name) DB_NAME="${2:-}"; shift 2;;
      --db-user) DB_USER="${2:-}"; shift 2;;
      --db-pass) DB_PASS="${2:-}"; shift 2;;
      --timezone) TIMEZONE="${2:-}"; shift 2;;
      --no-ssl) SSL=0; shift;;
      --no-firewall) FIREWALL=0; shift;;
      --no-whatsapp) WHATSAPP=0; shift;;
      --no-realtime) REALTIME=0; shift;;
      --non-interactive) NONINTERACTIVE=1; shift;;
      --repair) REPAIR=1; shift;;
      --dry-run) DRY_RUN=1; shift;;
      --skip-precheck) SKIP_PRECHECK=1; shift;;
      -h|--help) usage; exit 0;;
      *) die "Unknown option: $1 (see --help)";;
    esac
  done
  INSTALL_TYPE=${INSTALL_TYPE,,}
  [[ "$INSTALL_TYPE" =~ ^(production|staging|dr)$ ]] || die "--installation-type must be production, staging or dr"
  CHANNEL=${CHANNEL,,}
  [[ "$CHANNEL" =~ ^(stable|beta|lts)$ ]] || die "--channel must be stable, beta or lts"
  if [[ $OFFLINE -eq 1 && ( -z "$PACKAGE" || -z "$MANIFEST" ) ]]; then die "--offline needs --package and --manifest"; fi
  if [[ -n "$PACKAGE" || -n "$MANIFEST" ]]; then [[ -f "$PACKAGE" && -f "$MANIFEST" ]] || die "--package and --manifest must both name existing files"; fi
}

# Ask on the terminal even when the script itself arrives on stdin (curl … | sudo bash).
ask() { # ask VAR "Prompt" [default]
  local var=$1 prompt=$2 def=${3:-} val=""
  [[ -n "${!var:-}" ]] && return 0
  [[ $NONINTERACTIVE -eq 1 ]] && { [[ -n "$def" ]] && { printf -v "$var" '%s' "$def"; return 0; }; die "Missing $prompt (give it as an option)"; }
  [[ -r /dev/tty ]] || die "Missing $prompt and no terminal to ask on (give it as an option)"
  if [[ -n "$def" ]]; then read -r -p "  $prompt [$def]: " val </dev/tty; val=${val:-$def}; else read -r -p "  $prompt: " val </dev/tty; fi
  printf -v "$var" '%s' "$val"
}
randpw() { local s; s=$(head -c 192 /dev/urandom | base64 | tr -dc 'A-Za-z0-9'); printf '%s' "${s:0:${1:-32}}"; }
has_line() { grep -qE -- "$1" <<<"$2"; }

# ---------------------------------------------------------------------------
# 1. The server
# ---------------------------------------------------------------------------
detect_os() {
  # shellcheck disable=SC1091
  . /etc/os-release
  OS_ID="$ID"; OS_VER="${VERSION_ID%%.*}"; OS_FULL="$VERSION_ID"; OS_CODENAME="${VERSION_CODENAME:-}"; OS_NAME="$PRETTY_NAME"
  case "$OS_ID" in
    ubuntu|debian) FAMILY=debian;;
    rhel|rocky|almalinux|centos|ol) FAMILY=rhel;;
    *) FAMILY=unknown;;
  esac
  if [[ $FAMILY == debian ]]; then
    PKG=apt; WEB_USER=www-data; PHP_BIN="/usr/bin/php$PHP_V"; FPM_SERVICE="php$PHP_V-fpm"
    FPM_CONF_DIR="/etc/php/$PHP_V/fpm/conf.d"; PHP_CLI_INI_DIR="/etc/php/$PHP_V/cli/conf.d"; FPM_POOL_DIR="/etc/php/$PHP_V/fpm/pool.d"
    FPM_SOCK=/run/php/worksuite.sock; NGINX_SITE=/etc/nginx/sites-available/worksuite.conf; MARIADB_CNF=/etc/mysql/mariadb.conf.d/60-worksuite.cnf
    REDIS_SERVICE=redis-server; CRON_SERVICE=cron
  elif [[ $FAMILY == rhel ]]; then
    PKG=dnf; [[ "$OS_VER" == 7 ]] && { PKG=yum; LEGACY=1; }
    WEB_USER=nginx; PHP_BIN=/usr/bin/php; FPM_SERVICE=php-fpm
    FPM_CONF_DIR=/etc/php.d; PHP_CLI_INI_DIR=/etc/php.d; FPM_POOL_DIR=/etc/php-fpm.d
    FPM_SOCK=/run/php-fpm/worksuite.sock; NGINX_SITE=/etc/nginx/conf.d/worksuite.conf; MARIADB_CNF=/etc/my.cnf.d/60-worksuite.cnf
    REDIS_SERVICE=redis; CRON_SERVICE=crond
  fi
}

precheck() {
  step "Checking the server"
  [[ $EUID -eq 0 ]] || die "Run as root: sudo bash install.sh"
  touch "$LOG"; chmod 600 "$LOG"
  detect_os
  case "$OS_ID-$OS_VER" in
    ubuntu-22|ubuntu-24|debian-12|rhel-9|rocky-9|almalinux-9|centos-9|ol-9) ok "OS $OS_NAME";;
    centos-7|rhel-7) warn "OS $OS_NAME — end of life; supported on a best-effort basis (third-party PHP and MariaDB repositories)";;
    *) [[ $SKIP_PRECHECK -eq 1 && $FAMILY != unknown ]] && warn "Untested OS $OS_NAME (continuing)" || die "Unsupported OS $OS_NAME. Use Ubuntu 22.04/24.04, Debian 12, or RHEL/Rocky/AlmaLinux 9.";;
  esac
  ARCH=$(uname -m); [[ "$ARCH" =~ ^(x86_64|aarch64)$ ]] || die "Unsupported CPU architecture $ARCH"
  ok "Architecture $ARCH"
  local disk_gb cpus
  RAM_MB=$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo); cpus=$(nproc)
  disk_gb=$(df -BG --output=avail /var 2>/dev/null | tail -1 | tr -dc '0-9'); disk_gb=${disk_gb:-0}
  (( RAM_MB >= 1800 )) || { [[ $SKIP_PRECHECK -eq 1 ]] || die "At least 2 GB RAM required (found ${RAM_MB} MB)."; }
  (( RAM_MB >= 3800 )) && ok "RAM ${RAM_MB} MB, ${cpus} CPU" || warn "RAM ${RAM_MB} MB — 4 GB or more recommended; swap will be added"
  (( disk_gb >= 15 )) && ok "Free disk ${disk_gb} GB on /var" || { [[ $SKIP_PRECHECK -eq 1 ]] || die "At least 15 GB free on /var required (found ${disk_gb} GB)."; }
  if [[ $REPAIR -eq 0 && -L "$INSTALL_DIR/current" ]]; then
    die "WorkSuite is already installed in $INSTALL_DIR. Use --repair, or update from Admin Center › System › Updates."
  fi
  for p in 80 443; do
    local l; l=$(ss -ltnpH "( sport = :$p )" 2>/dev/null || true)
    if [[ -n "$l" ]] && ! has_line nginx "$l"; then
      [[ $SKIP_PRECHECK -eq 1 ]] && warn "Port $p is used by another program" || die "Port $p is used by another program (Apache?). Stop it first."
    fi
  done
  ok "Ports 80 and 443 free"
  command -v curl >/dev/null || { [[ $FAMILY == debian ]] && run apt-get install -y curl || run "$PKG" install -y curl; }
}

collect_inputs() {
  step "Installation details"
  if [[ $OFFLINE -eq 0 ]]; then
    ask LICENSE_KEY "WorkSuite licence key (WS-XXXX-XXXX-XXXX-XXXX-XXXX)"
    LICENSE_KEY=$(tr '[:lower:]' '[:upper:]' <<<"${LICENSE_KEY// /}")
    [[ "$LICENSE_KEY" =~ ^WS-?([A-Z0-9]{4}-?){5}$ ]] || die "That does not look like a WorkSuite licence key (WS-XXXX-XXXX-XXXX-XXXX-XXXX)."
  fi
  ask DOMAIN "Domain name for WorkSuite (e.g. erp.example.com)"
  DOMAIN=${DOMAIN,,}; [[ "$DOMAIN" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] || die "Invalid domain: $DOMAIN"
  ask ADMIN_EMAIL "Administrator email"
  [[ "$ADMIN_EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] || die "Invalid email: $ADMIN_EMAIL"
  ask ORG_NAME "Organisation name" "$DOMAIN"
  [[ -n "$TIMEZONE" ]] || TIMEZONE=$(timedatectl show -p Timezone --value 2>/dev/null || echo UTC)
  [[ $OFFLINE -eq 1 ]] && return 0
  local pub dns
  pub=$(curl -4 -fsS --max-time 8 https://api.ipify.org 2>/dev/null || true)
  dns=$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk 'NR==1{print $1}' || true)
  if [[ -n "$pub" && "$pub" == "$dns" ]]; then ok "DNS $DOMAIN → $dns (this server)"
  else warn "DNS for $DOMAIN points to '${dns:-nothing}', this server is '${pub:-unknown}'. Let's Encrypt fails until DNS is right."; fi
}

# ---------------------------------------------------------------------------
# 2. The licence (before anything is installed, so a wrong key costs nothing)
# ---------------------------------------------------------------------------
dc_post() { # dc_post PATH JSON → response body on stdout; non-2xx dies with the Download Center's message
  local out code
  out=$(mktemp)
  code=$(curl -sS --max-time 60 -o "$out" -w '%{http_code}' -H 'Content-Type: application/json' -H 'Accept: application/json' \
         -H "User-Agent: worksuite-installer/$INSTALLER_VERSION" --data-binary "$2" "$WS_DOWNLOAD_CENTER$1") || { rm -f "$out"; die "The WorkSuite Download Center could not be reached ($WS_DOWNLOAD_CENTER)."; }
  if [[ "$code" != 2* ]]; then
    local msg; msg=$(sed -n 's/.*"message":"\([^"]*\)".*/\1/p' "$out" | head -1); rm -f "$out"
    die "${msg:-The WorkSuite Download Center answered $code.}"
  fi
  cat "$out"; rm -f "$out"
}

check_license() {
  if [[ $OFFLINE -eq 1 ]]; then
    step "Licence: offline activation after installing"
    EDITION=$(unzip -p "$PACKAGE" worksuite-package.json 2>/dev/null | sed -n 's/.*"edition"[[:space:]]*:[[:space:]]*"\([a-z]*\)".*/\1/p' | head -1)
    [[ "$EDITION" =~ ^(free|professional|business|enterprise)$ ]] || die "Cannot read the package's edition."
    [[ "$EDITION" == professional ]] && EDITION=free
    ok "${EDITION^} package; the licence is activated offline at the end"
    return 0
  fi
  step "Checking the licence with the WorkSuite Download Center"
  local body; body=$(dc_post /api/v1/server/licenses/inspect "{\"license_key\":\"$LICENSE_KEY\"}")
  local status org until
  status=$(jfield "$body" status); EDITION=$(jfield "$body" edition); org=$(jfield "$body" organization); until=$(jfield "$body" license_expires_at)
  [[ "$status" == ACTIVE ]] || die "This licence is $status: $(jfield "$body" message)"
  [[ "$EDITION" =~ ^(free|business|enterprise)$ ]] || die "The licence has an unknown edition: $EDITION"
  ok "Licence active · ${EDITION^} · licensed to ${org:-?} · until ${until%%T*}"
}

# Read one string or number field of a flat JSON object without depending on jq (installed later).
jfield() { sed -n "s/.*\"$2\":\"\{0,1\}\([^\",}]*\)\"\{0,1\}[,}].*/\1/p" <<<"$1" | head -1; }

# ---------------------------------------------------------------------------
# 3. System packages
# ---------------------------------------------------------------------------
install_packages_debian() {
  export DEBIAN_FRONTEND=noninteractive
  run apt-get update -y
  run apt-get install -y ca-certificates curl gnupg lsb-release unzip zip jq acl rsync zstd cron logrotate ufw \
      apt-transport-https git sudo tzdata fail2ban
  local cand; cand=$(apt-cache policy "php$PHP_V-fpm" 2>/dev/null | awk '/Candidate/{print $2}')
  if [[ -z "$cand" || "$cand" == "(none)" ]]; then
    # Ubuntu up to 24.04: the ondrej PPA. From 26.04 the PPA has no builds; they moved to packages.sury.org/php.
    if [[ "$OS_ID" == ubuntu && "$OS_VER" -lt 26 ]]; then run apt-get install -y software-properties-common; run add-apt-repository -y ppa:ondrej/php
    else
      run curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury-php.gpg
      [[ $DRY_RUN -eq 1 ]] || echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $OS_CODENAME main" >/etc/apt/sources.list.d/sury-php.list
    fi
    run apt-get update -y
  fi
  local exts=(fpm cli common mysql intl mbstring xml curl zip gd bcmath imap opcache redis readline soap)
  run apt-get install -y "${exts[@]/#/php$PHP_V-}"
  run apt-get install -y nginx redis-server
  [[ $EXTERNAL_DB -eq 1 ]] || run apt-get install -y mariadb-server mariadb-client
  if ! command -v node >/dev/null || [[ "$(node -v | tr -dc '0-9.' | cut -d. -f1)" -lt $NODE_MAJOR ]]; then
    run bash -c "curl -fsSL https://deb.nodesource.com/setup_${NODE_MAJOR}.x | bash -"
    run apt-get install -y nodejs
  fi
  [[ $SSL -eq 1 ]] && run apt-get install -y certbot python3-certbot-nginx
  [[ $WHATSAPP -eq 1 ]] && { apt-get install -y libnss3 libatk1.0-0 libatk-bridge2.0-0 libcups2 libdrm2 libxkbcommon0 libxcomposite1 libxdamage1 libxrandr2 libgbm1 libpango-1.0-0 libcairo2 fonts-liberation >>"$LOG" 2>&1 || warn "Some WhatsApp bridge libraries did not install"; }
  return 0
}

install_packages_rhel() {
  local el="$OS_VER" mgr="$PKG"
  run "$mgr" install -y epel-release || run "$mgr" install -y "https://dl.fedoraproject.org/pub/epel/epel-release-latest-$el.noarch.rpm"
  if [[ $LEGACY -eq 1 ]]; then
    # CentOS 7: PHP 8.3 from Remi (php83 collection is not used: the module-style remi-php83 repo gives /usr/bin/php).
    run yum install -y "https://rpms.remirepo.net/enterprise/remi-release-7.rpm" yum-utils
    run yum-config-manager --enable remi-php83
    # MariaDB 10.11 from MariaDB's own repository (EL7 ships 5.5, far too old).
    [[ $EXTERNAL_DB -eq 1 ]] || run bash -c "curl -fsSL https://r.mariadb.com/downloads/mariadb_repo_setup | bash -s -- --mariadb-server-version=mariadb-10.11"
  else
    run dnf install -y "https://rpms.remirepo.net/enterprise/remi-release-$el.rpm"
    run dnf module reset -y php
    run dnf module enable -y "php:remi-$PHP_V"
  fi
  run "$mgr" install -y ca-certificates curl unzip zip jq acl rsync zstd cronie logrotate git sudo tar policycoreutils-python-utils firewalld fail2ban \
      || run "$mgr" install -y ca-certificates curl unzip zip jq acl rsync zstd cronie logrotate git sudo tar policycoreutils-python firewalld
  run "$mgr" install -y php php-fpm php-cli php-common php-mysqlnd php-intl php-mbstring php-xml php-zip php-gd php-bcmath php-imap php-opcache php-pecl-redis5 php-soap php-sodium php-process
  run "$mgr" install -y nginx redis
  if [[ $EXTERNAL_DB -eq 0 ]]; then
    if [[ $LEGACY -eq 1 ]]; then run yum install -y MariaDB-server MariaDB-client; else run dnf install -y mariadb-server mariadb; fi
  fi
  if ! command -v node >/dev/null || [[ "$(node -v | tr -dc '0-9.' | cut -d. -f1)" -lt $NODE_MAJOR ]]; then
    run bash -c "curl -fsSL https://rpm.nodesource.com/setup_${NODE_MAJOR}.x | bash -"
    run "$mgr" install -y nodejs
  fi
  [[ $SSL -eq 1 ]] && run "$mgr" install -y certbot python3-certbot-nginx
  [[ $WHATSAPP -eq 1 ]] && { "$mgr" install -y nss atk at-spi2-atk cups-libs libdrm libxkbcommon libXcomposite libXdamage libXrandr mesa-libgbm pango cairo liberation-fonts >>"$LOG" 2>&1 || warn "Some WhatsApp bridge libraries did not install"; }
  # SELinux: Nginx talks to PHP-FPM's socket, Redis, MariaDB and the local realtime gateway, and PHP reaches the Download Center.
  if command -v getenforce >/dev/null && [[ "$(getenforce)" != Disabled ]]; then
    run setsebool -P httpd_can_network_connect 1
    run setsebool -P httpd_can_network_connect_db 1
    run setsebool -P httpd_can_sendmail 1
  fi
  return 0
}

install_packages() {
  step "Installing system packages"
  [[ $DRY_RUN -eq 1 ]] && { info "(dry run) $FAMILY packages: Nginx, PHP $PHP_V-FPM, MariaDB, Redis, Node.js $NODE_MAJOR, Certbot"; return 0; }
  if [[ $FAMILY == debian ]]; then install_packages_debian; else install_packages_rhel; fi
  "$PHP_BIN" -v 2>/dev/null | head -1 | grep -q "PHP $PHP_V" || die "PHP $PHP_V did not install (found: $("$PHP_BIN" -v 2>/dev/null | head -1))."
  "$PHP_BIN" -r 'exit(function_exists("sodium_crypto_sign_verify_detached") ? 0 : 1);' || die "PHP has no sodium; the release signature cannot be checked."
  ok "PHP $PHP_V with intl, mbstring, sodium, redis, imap, gd, zip"
  run timedatectl set-timezone "$TIMEZONE"
  run systemctl enable --now "$CRON_SERVICE" "$REDIS_SERVICE" nginx "$FPM_SERVICE"
  [[ $EXTERNAL_DB -eq 1 ]] || run systemctl enable --now mariadb
  ok "Nginx, Redis, $( [[ $EXTERNAL_DB -eq 1 ]] && echo 'external database' || echo MariaDB ), Node.js $(node -v 2>/dev/null || echo "$NODE_MAJOR")"
  if [[ $SWAP_MB -gt 0 && ${RAM_MB:-0} -lt 3800 && ! -e /swapfile ]]; then
    run fallocate -l "${SWAP_MB}M" /swapfile; run chmod 600 /swapfile; run mkswap /swapfile; run swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >>/etc/fstab
    ok "Swap ${SWAP_MB} MB"
  fi
}

# ---------------------------------------------------------------------------
# 4. Users and directories (the layout the updater agent expects: ops/systemupdate/README.md)
# ---------------------------------------------------------------------------
prepare_layout() {
  step "Creating users and directories"
  [[ $DRY_RUN -eq 1 ]] && { info "(dry run) $INSTALL_DIR/{releases,shared,backups,current}"; return 0; }
  id "$DEPLOY_USER" >/dev/null 2>&1 || useradd --system --create-home --home-dir /var/lib/worksuite --shell /sbin/nologin "$DEPLOY_USER"
  install -d -m 0755 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$INSTALL_DIR" "$INSTALL_DIR/releases"
  # The app user may list the backups (System › Monitoring checks their age and size) but read none of them.
  install -d -m 0750 -o "$DEPLOY_USER" -g "$WEB_USER" "$INSTALL_DIR/backups"
  install -d -m 0750 -o "$DEPLOY_USER" -g "$WEB_USER" "$INSTALL_DIR/shared" "$INSTALL_DIR/shared/public"
  install -d -m 0770 -o "$WEB_USER" -g "$WEB_USER" "$INSTALL_DIR/shared/writable" "$INSTALL_DIR/shared/public/uploads"
  for d in cache logs session uploads debugbar secrets; do install -d -m 0770 -o "$WEB_USER" -g "$WEB_USER" "$INSTALL_DIR/shared/writable/$d"; done
  install -d -m 0750 -o root -g "$WEB_USER" /etc/worksuite
  if command -v semanage >/dev/null && [[ "$(getenforce 2>/dev/null)" != Disabled ]]; then
    semanage fcontext -a -t httpd_sys_rw_content_t "$INSTALL_DIR/shared(/.*)?" >>"$LOG" 2>&1 || true
    semanage fcontext -a -t httpd_sys_content_t "$INSTALL_DIR/releases(/.*)?" >>"$LOG" 2>&1 || true
    restorecon -R "$INSTALL_DIR" >>"$LOG" 2>&1 || true
  fi
  ok "$INSTALL_DIR/{releases,shared,backups,current}"
}

# ---------------------------------------------------------------------------
# 5. Package: download, verify the signed manifest and the SHA-256
# ---------------------------------------------------------------------------
verify_manifest() { # verify_manifest FILE — Ed25519 over canonical JSON (sorted keys, no "signature"), the release key pinned above
  "$PHP_BIN" -r '
    $doc = json_decode((string) file_get_contents($argv[1]), true);
    if (! is_array($doc) || ($doc["key_id"] ?? "") !== $argv[2] || ! is_string($doc["signature"] ?? null)) { fwrite(STDERR, "not a manifest signed by " . $argv[2] . "\n"); exit(1); }
    $b64 = static fn (string $s) => base64_decode(strtr($s, "-_", "+/") . str_repeat("=", (4 - strlen($s) % 4) % 4), true);
    $sig = $b64($doc["signature"]); unset($doc["signature"]);
    $sort = function ($v) use (&$sort) { if (! is_array($v)) { return $v; } if (array_is_list($v)) { return array_map($sort, $v); } ksort($v, SORT_STRING); foreach ($v as $k => $x) { $v[$k] = $sort($x); } return $v; };
    $msg = json_encode($sort($doc), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit(sodium_crypto_sign_verify_detached($sig, $msg, $b64($argv[3])) ? 0 : 1);
  ' "$1" "$WS_RELEASE_KEY_ID" "$WS_RELEASE_KEY"
}

fetch_package() {
  step "Downloading and verifying WorkSuite Server"
  WORK=$(mktemp -d /tmp/worksuite-install.XXXXXX)
  [[ $DRY_RUN -eq 1 ]] && { REL_VERSION=dry-run; REL_BUILD=0; info "(dry run) would download the newest ${EDITION^} package on $CHANNEL"; return 0; }
  if [[ -z "$PACKAGE" ]]; then
    local req="{\"license_key\":\"$LICENSE_KEY\",\"channel\":\"$CHANNEL\"${TARGET_VERSION:+,\"version\":\"$TARGET_VERSION\"}}"
    dc_post /api/v1/server/install/package "$req" >"$WORK/offer.json"
    jq -e . "$WORK/offer.json" >/dev/null 2>&1 || die "The Download Center's answer was not readable."
    jq '.manifest' "$WORK/offer.json" >"$WORK/manifest.json"
    PKG_SHA=$(jq -r '.sha256' "$WORK/offer.json")
    info "WorkSuite Server $(jq -r .version "$WORK/offer.json") build $(jq -r .build "$WORK/offer.json") · $(jq -r .file_name "$WORK/offer.json") ($(( $(jq -r .size "$WORK/offer.json") / 1048576 )) MB)"
    curl -fL --retry 5 --retry-delay 3 -C - --max-time 3600 "$(jq -r .download_url "$WORK/offer.json")" -o "$WORK/package.zip" >>"$LOG" 2>&1 || die "The package download failed."
    PACKAGE="$WORK/package.zip"; MANIFEST="$WORK/manifest.json"
  fi
  verify_manifest "$MANIFEST" 2>>"$LOG" || die "The release manifest's signature is not valid for the WorkSuite release key. Nothing was installed."
  ok "Release manifest signed by $WS_RELEASE_KEY_ID"
  local sha; sha=$(sha256sum "$PACKAGE" | cut -d' ' -f1)
  jq -e --arg s "$sha" '[.packages[] | .sha256] | index($s) != null' "$MANIFEST" >/dev/null || die "The package's SHA-256 is not in the signed manifest. Nothing was installed."
  [[ -z "$PKG_SHA" || "$PKG_SHA" == "$sha" ]] || die "The package differs from the one the Download Center offered."
  ok "Package SHA-256 $sha is in the signed manifest"
  REL_VERSION=$(jq -r '.version' "$MANIFEST"); REL_BUILD=$(jq -r '.build // .build_number' "$MANIFEST")
}

extract_package() {
  step "Unpacking the release"
  [[ $DRY_RUN -eq 1 ]] && { REL_DIR="$INSTALL_DIR/releases/DRYRUN"; info "(dry run)"; return 0; }
  local names perms; names=$(unzip -Z1 "$PACKAGE") || die "The package is not a readable ZIP."
  perms=$(unzip -Zl "$PACKAGE" | awk '{print $1}')
  if has_line '(^/|(^|/)\.\.(/|$))' "$names"; then die "The package contains unsafe paths."; fi
  if has_line '^l' "$perms"; then die "The package contains symbolic links."; fi
  REL_DIR="$INSTALL_DIR/releases/${REL_VERSION}+${REL_BUILD}"
  [[ ! -e "$REL_DIR" ]] || { [[ $REPAIR -eq 1 ]] && warn "Release directory exists; reusing it" || die "$REL_DIR already exists"; }
  install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$REL_DIR"
  unzip -q -o "$PACKAGE" -d "$REL_DIR" >>"$LOG" 2>&1
  [[ -f "$REL_DIR/worksuite-package.json" ]] || die "worksuite-package.json is missing from the package."
  local pe; pe=$(jq -r .edition "$REL_DIR/worksuite-package.json")
  [[ "$pe" == "$EDITION" || "$pe" == all ]] || die "The package is for the $pe edition; the licence is $EDITION."
  (cd "$REL_DIR" && sha256sum --quiet -c files.sha256) >>"$LOG" 2>&1 || die "A file does not match files.sha256 after unpacking."
  ok "$(wc -l <"$REL_DIR/files.sha256") files match files.sha256"
  for f in .env writable public/uploads .git; do [[ ! -e "$REL_DIR/$f" ]] || die "The package contains $f, which a release must not."; done
  chown -R "$DEPLOY_USER:$DEPLOY_USER" "$REL_DIR"
  find "$REL_DIR" -type d -exec chmod 0755 {} + ; find "$REL_DIR" -type f -exec chmod 0644 {} +
  chmod 0755 "$REL_DIR/spark"
  # Shared state lives outside every release, linked in (as the updater agent does).
  ln -sfn "$INSTALL_DIR/shared/writable" "$REL_DIR/writable"
  ln -sfn "$INSTALL_DIR/shared/.env" "$REL_DIR/.env"
  ln -sfn "$INSTALL_DIR/shared/public/uploads" "$REL_DIR/public/uploads"
  # The verified manifest stays with the release: Admin Center › License › Code integrity proves every file against it.
  install -m 0644 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$MANIFEST" "$REL_DIR/release-manifest.json"
  ok "WorkSuite Server $REL_VERSION build $REL_BUILD (${EDITION^})"
}

# put_file [install options] DEST <<EOF — a heredoc into a file with mode/owner. Through a temp file:
# uutils' install (Ubuntu 26.04's coreutils) fails reading /dev/stdin when DEST already exists.
put_file() {
  local dest="${@: -1}" tmp; tmp=$(mktemp)
  cat >"$tmp"
  install "${@:1:$#-1}" "$tmp" "$dest"; local rc=$?
  rm -f "$tmp"; return $rc
}

# ---------------------------------------------------------------------------
# 6. Database
# ---------------------------------------------------------------------------
setup_database() {
  step "Preparing the database"
  [[ -n "$DB_PASS" ]] || DB_PASS=$(randpw 32)
  [[ $DRY_RUN -eq 1 ]] && { info "(dry run)"; return 0; }
  if [[ $EXTERNAL_DB -eq 0 ]]; then
    install -d "$(dirname "$MARIADB_CNF")"
    put_file -m 0644 "$MARIADB_CNF" <<EOF
[mysqld]
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
innodb_buffer_pool_size = $(( RAM_MB / 4 ))M
max_allowed_packet = 256M
bind-address = 127.0.0.1
EOF
    systemctl restart mariadb
    mysql --protocol=socket -uroot <<SQL
DELETE FROM mysql.user WHERE User='';
DROP DATABASE IF EXISTS test;
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    ok "MariaDB database '$DB_NAME' and user '$DB_USER'"
  else
    mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -e "SELECT 1" "$DB_NAME" >/dev/null 2>&1 || die "Cannot connect to $DB_USER@$DB_HOST/$DB_NAME"
    ok "External database reachable"
  fi
}

# ---------------------------------------------------------------------------
# 7. Configuration (.env)
# ---------------------------------------------------------------------------
write_env() {
  step "Writing configuration"
  local env="$INSTALL_DIR/shared/.env"
  if [[ -f "$env" ]]; then ok "Keeping the existing $env"; return 0; fi
  [[ $DRY_RUN -eq 1 ]] && { info "(dry run)"; return 0; }
  local enc jobkey jwt wa mon_units
  enc=$("$PHP_BIN" -r 'echo bin2hex(random_bytes(32));')
  jobkey=$("$PHP_BIN" -r 'echo base64_encode(random_bytes(32));')
  jwt=$(randpw 64); wa=$(randpw 48)
  ADMIN_PASS=$(randpw 20)
  mon_units="nginx,$FPM_SERVICE,$REDIS_SERVICE,worksuite-updater"
  [[ $EXTERNAL_DB -eq 1 ]] || mon_units="$mon_units,mariadb"
  [[ $REALTIME -eq 1 ]] && mon_units="$mon_units,worksuite-realtime"
  [[ $WHATSAPP -eq 1 ]] && mon_units="$mon_units,worksuite-whatsapp"
  put_file -m 0640 -o "$DEPLOY_USER" -g "$WEB_USER" "$env" <<EOF
# WorkSuite Server — written by the WorkSuite installer $INSTALLER_VERSION on $(date -u +%FT%TZ)
CI_ENVIRONMENT = production
app.baseURL = 'https://$DOMAIN/'
app.forceGlobalSecureRequests = $( [[ $SSL -eq 1 ]] && echo true || echo false )
app.appTimezone = '$TIMEZONE'

database.default.hostname = $DB_HOST
database.default.port = $DB_PORT
database.default.database = $DB_NAME
database.default.username = $DB_USER
database.default.password = '$DB_PASS'
database.default.DBDriver = MySQLi
database.default.charset = utf8mb4
database.default.DBCollat = utf8mb4_unicode_ci

encryption.key = hex2bin:$enc
jwt.secret = '$jwt'

session.driver = 'CodeIgniter\Session\Handlers\RedisHandler'
session.savePath = 'tcp://127.0.0.1:6379?database=1'
cache.handler = redis

# The realtime gateway's key is derived from jwt.secret by the app (spark realtime:secret --write, step 10).
# The WhatsApp bridge reads the same key from /etc/worksuite-whatsapp.env (WA_SERVICE_KEY).
whatsapp.bridge_key = '$wa'

# Monitoring (System › Monitoring): what this installer runs and where its backups go.
monitor.services = '$mon_units'
monitor.backup_path = '$INSTALL_DIR/backups'
monitor.backup_glob = 'nightly-*/db.sql.zst'

# Updates and licensing (System › Updates, System › License)
systemupdate.installRoot = $INSTALL_DIR
systemupdate.mode = SELF_HOSTED
systemupdate.jobSigningKey = '$jobkey'
systemupdate.edition = $EDITION
systemupdate.channel = $CHANNEL
license.enforcement = enforce
license.installationType = ${INSTALL_TYPE^^}
license.fingerprintMode = $FP_MODE

# The first administrator (InstallSeeder); removed by the installer once created.
install.admin_email = '$ADMIN_EMAIL'
install.admin_password = '$ADMIN_PASS'
EOF
  # No REALTIME_SECRET here: the gateway reads the app's own key from REALTIME_SECRET_FILE (step 10).
  put_file -m 0640 -o root -g "$WEB_USER" /etc/worksuite/realtime.env <<EOF
REALTIME_BIND=127.0.0.1
REALTIME_PORT=8091
REALTIME_SECRET_FILE=$INSTALL_DIR/shared/writable/realtime/secret
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
EOF
  # The path whatsapp-bridge/worksuite-whatsapp.service reads (and production uses).
  put_file -m 0640 -o root -g root /etc/worksuite-whatsapp.env <<EOF
WA_SERVICE_KEY=$wa
EOF
  ok "$env (0640, $DEPLOY_USER:$WEB_USER)"
}

# ---------------------------------------------------------------------------
# 8. PHP-FPM and Nginx
# ---------------------------------------------------------------------------
configure_php() {
  step "Configuring PHP-FPM"
  [[ $DRY_RUN -eq 1 ]] && { info "(dry run)"; return 0; }
  put_file -m 0644 "$FPM_CONF_DIR/90-worksuite.ini" <<'EOF'
memory_limit = 512M
upload_max_filesize = 512M
post_max_size = 520M
max_execution_time = 120
max_input_vars = 5000
expose_php = Off
opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 60000
opcache.validate_timestamps = 0
realpath_cache_size = 4096K
EOF
  if [[ "$PHP_CLI_INI_DIR" != "$FPM_CONF_DIR" ]]; then
    sed 's/^opcache.validate_timestamps.*/opcache.validate_timestamps = 1/' "$FPM_CONF_DIR/90-worksuite.ini" >"$PHP_CLI_INI_DIR/90-worksuite.ini"
  fi
  local children=$(( RAM_MB / 80 )); (( children < 10 )) && children=10; (( children > 120 )) && children=120
  install -d "$(dirname "$FPM_SOCK")"
  put_file -m 0644 "$FPM_POOL_DIR/worksuite.conf" <<EOF
[worksuite]
user = $WEB_USER
group = $WEB_USER
listen = $FPM_SOCK
listen.owner = $WEB_USER
listen.group = $WEB_USER
pm = dynamic
pm.max_children = $children
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
pm.max_requests = 1000
EOF
  systemctl restart "$FPM_SERVICE"
  ok "Pool 'worksuite' ($children workers), opcache on"
}

configure_nginx() {
  step "Configuring Nginx"
  [[ $DRY_RUN -eq 1 ]] && { info "(dry run)"; return 0; }
  local fastcgi='include fastcgi_params;'
  [[ -f /etc/nginx/snippets/fastcgi-php.conf ]] && fastcgi='include snippets/fastcgi-php.conf;'
  put_file -m 0644 "$NGINX_SITE" <<EOF
# WorkSuite — written by the WorkSuite installer
map \$http_upgrade \$connection_upgrade { default upgrade; '' close; }
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    root $INSTALL_DIR/current/public;
    index index.php index.html;
    client_max_body_size 520M;
    server_tokens off;

    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header X-Frame-Options SAMEORIGIN always;

    location ~ /\.(?!well-known) { deny all; }

    location /realtime/ {
        proxy_pass http://127.0.0.1:8091/;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection \$connection_upgrade;
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_read_timeout 3600s;
    }

    location / {
        try_files \$uri \$uri/ /index.php\$is_args\$args;
    }

    location ~ \.php\$ {
        $fastcgi
        fastcgi_pass unix:$FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_read_timeout 300;
    }
}
EOF
  if [[ $FAMILY == debian ]]; then
    ln -sfn "$NGINX_SITE" /etc/nginx/sites-enabled/worksuite.conf
    rm -f /etc/nginx/sites-enabled/default
  fi
  nginx -t >>"$LOG" 2>&1 || die "The Nginx configuration test failed (see the log)."
  systemctl reload nginx
  ok "Site $DOMAIN → $INSTALL_DIR/current/public"
}

# ---------------------------------------------------------------------------
# 9. Release, migrations, administrator, licence
# ---------------------------------------------------------------------------
spark() { (cd "$INSTALL_DIR/current" && sudo -u "$WEB_USER" -H "$PHP_BIN" spark "$@"); }

activate_release() {
  step "Migrating, creating the administrator and activating the licence"
  [[ $DRY_RUN -eq 1 ]] && { info "(dry run)"; return 0; }
  ln -sfn "$REL_DIR" "$INSTALL_DIR/current.new" && mv -Tf "$INSTALL_DIR/current.new" "$INSTALL_DIR/current"
  printf '{"version":"%s+%s","edition":"%s","deployed_at":"%s"}\n' "$REL_VERSION" "$REL_BUILD" "$EDITION" "$(date -u +%FT%TZ)" >"$REL_DIR/RELEASE"
  systemctl reload "$FPM_SERVICE"
  spark migrate --all >>"$LOG" 2>&1 || die "The database migration failed (see $LOG)."
  ok "Database migrated"
  spark db:seed 'Modules\Core\Database\Seeds\InstallSeeder' >>"$LOG" 2>&1 || die "Creating the administrator failed (see $LOG)."
  # The password stays only in the root-only credentials file.
  sed -i '/^install\.admin_/d' "$INSTALL_DIR/shared/.env"
  ok "Administrator $ADMIN_EMAIL (asked to change the password at first sign-in)"
  if [[ $OFFLINE -eq 1 ]]; then
    (cd "$INSTALL_DIR/current" && sudo -u "$WEB_USER" -H "$PHP_BIN" spark license:export-request --out "$INSTALL_DIR/shared/writable/worksuite-request.wsr") >>"$LOG" 2>&1 || die "The offline activation request could not be written."
    cp "$INSTALL_DIR/shared/writable/worksuite-request.wsr" /root/worksuite-request.wsr
    ok "Offline activation request: /root/worksuite-request.wsr"
  else
    spark license:activate --key "$LICENSE_KEY" >>"$LOG" 2>&1 || die "The licence could not be activated on this server: $(grep -E 'LICENSE_|could not' "$LOG" | tail -1)"
    ok "Licence activated on this server"
  fi
}

# ---------------------------------------------------------------------------
# 10. Services: updater agent, realtime, WhatsApp bridge
# ---------------------------------------------------------------------------
install_services() {
  step "Installing services"
  [[ $DRY_RUN -eq 1 ]] && { info "(dry run)"; return 0; }
  local ops="$INSTALL_DIR/current/ops/systemupdate"
  # The updater agent (System › Updates): the only thing that writes releases/, as $DEPLOY_USER.
  sed -e "s#/usr/bin/php8.3#$PHP_BIN#g" -e "s#/var/www/worksuite#$INSTALL_DIR#g" "$ops/worksuite-updater.service" >/etc/systemd/system/worksuite-updater.service
  sed -e "s#php8.3-fpm#$FPM_SERVICE#g" "$ops/sudoers-worksuite-deploy" >/etc/sudoers.d/worksuite-deploy
  chmod 0440 /etc/sudoers.d/worksuite-deploy
  visudo -cf /etc/sudoers.d/worksuite-deploy >>"$LOG" 2>&1 || die "The sudoers file for the updater did not validate."
  systemctl daemon-reload
  systemctl enable --now worksuite-updater >>"$LOG" 2>&1 && ok "worksuite-updater agent" || warn "The updater agent did not start (see journalctl -u worksuite-updater)"
  if [[ $REALTIME -eq 1 && -f "$INSTALL_DIR/current/realtime/server.js" ]]; then
    put_file -m 0644 /etc/systemd/system/worksuite-realtime.service <<EOF
[Unit]
Description=WorkSuite realtime gateway
After=network.target $REDIS_SERVICE.service
[Service]
Type=simple
User=$WEB_USER
Group=$WEB_USER
EnvironmentFile=/etc/worksuite/realtime.env
WorkingDirectory=$INSTALL_DIR/current/realtime
ExecStart=/usr/bin/node $INSTALL_DIR/current/realtime/server.js
Restart=always
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
[Install]
WantedBy=multi-user.target
EOF
    (cd "$INSTALL_DIR/current/realtime" && { [[ -d node_modules || ! -f package.json ]] || npm ci --omit=dev >>"$LOG" 2>&1; }) || warn "Realtime dependencies did not install"
    # The key the app signs tickets with, derived from its own secrets: write it where REALTIME_SECRET_FILE points.
    spark realtime:secret --write >>"$LOG" 2>&1 || warn "The realtime key file was not written (spark realtime:secret --write)"
    systemctl daemon-reload; systemctl enable --now worksuite-realtime >>"$LOG" 2>&1 && ok "worksuite-realtime (127.0.0.1:8091)" || warn "The realtime gateway did not start"
  fi
  if [[ $WHATSAPP -eq 1 && -d "$INSTALL_DIR/current/whatsapp-bridge" ]]; then
    rsync -a --delete "$INSTALL_DIR/current/whatsapp-bridge/" /opt/worksuite-whatsapp/ >>"$LOG" 2>&1
    (cd /opt/worksuite-whatsapp && PUPPETEER_CACHE_DIR=/opt/worksuite-whatsapp/.cache npm ci --omit=dev >>"$LOG" 2>&1) || warn "WhatsApp bridge dependencies did not install; enable it later"
    if [[ -f /opt/worksuite-whatsapp/worksuite-whatsapp.service ]]; then
      install -m 0644 /opt/worksuite-whatsapp/worksuite-whatsapp.service /etc/systemd/system/worksuite-whatsapp.service
      systemctl daemon-reload; systemctl enable --now worksuite-whatsapp >>"$LOG" 2>&1 && ok "worksuite-whatsapp" || warn "The WhatsApp bridge did not start"
    fi
  fi
}

# ---------------------------------------------------------------------------
# 11. Background jobs
# ---------------------------------------------------------------------------
install_cron() {
  step "Scheduling background jobs"
  [[ $DRY_RUN -eq 1 ]] && { info "(dry run)"; return 0; }
  local P="cd $INSTALL_DIR/current && $PHP_BIN spark" U="$WEB_USER" L="$INSTALL_DIR/shared/writable/logs"
  cat >/etc/cron.d/worksuite <<EOF
# WorkSuite scheduled jobs — written by the WorkSuite installer $INSTALLER_VERSION
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * *   $U $P notify work 50 >> $L/notify.log 2>&1
*/5 * * * * $U $P notify release >/dev/null 2>&1
17 3 * * *  $U $P notify prune --delete >/dev/null 2>&1
* * * * *   $U $P mail:outbox >> $L/mail-outbox.log 2>&1
* * * * *   $U $P relay work 50 >/dev/null 2>&1
* * * * *   $U $P chat:close-idle >/dev/null 2>&1
* * * * *   $U $P pdf work 55 >/dev/null 2>&1
*/5 * * * * $U $P calendar:remind >/dev/null 2>&1
*/5 * * * * $U $P meetings:remind >/dev/null 2>&1
0 * * * *   $U $P tasks:remind >/dev/null 2>&1
* * * * *   $U $P monitor:collect >/dev/null 2>&1
47 4 * * *  $U $P monitor:prune >/dev/null 2>&1
# Updates and the licence (System › Updates, System › License)
17 * * * *  $U $P sysupdate:check >> $L/updates-check.log 2>&1
47 * * * *  $U $P license:validate >> $L/license.log 2>&1
# Housekeeping
30 2 * * *  root /usr/local/sbin/worksuite-backup >> /var/log/worksuite-backup.log 2>&1
17 4 * * *  root find $L -maxdepth 1 -name 'log-20*.log' -type f -mtime +90 -delete
EOF
  chmod 0644 /etc/cron.d/worksuite
  ok "$(grep -cE '^[*0-9]' /etc/cron.d/worksuite) jobs in /etc/cron.d/worksuite"
}

# ---------------------------------------------------------------------------
# 12. Backups
# ---------------------------------------------------------------------------
install_backup() {
  step "Nightly backups"
  [[ $DRY_RUN -eq 1 ]] && { info "(dry run)"; return 0; }
  put_file -m 0600 /etc/worksuite/backup.cnf <<EOF
[client]
host=$DB_HOST
port=$DB_PORT
user=$DB_USER
password=$DB_PASS
EOF
  put_file -m 0750 /usr/local/sbin/worksuite-backup <<EOF
#!/usr/bin/env bash
# Nightly WorkSuite backup: database and configuration. Keeps 14 days.
set -euo pipefail
D=$INSTALL_DIR/backups/nightly-\$(date +%F)
umask 077
install -d -m 0710 -g $WEB_USER "\$D"
mysqldump --defaults-extra-file=/etc/worksuite/backup.cnf --single-transaction --quick --routines --triggers $DB_NAME | zstd -q -T0 -o "\$D/db.sql.zst"
cp -a $INSTALL_DIR/shared/.env "\$D/env"
tar -C $INSTALL_DIR/shared/writable -cf "\$D/secrets.tar" secrets
readlink -f $INSTALL_DIR/current > "\$D/release"
sha256sum "\$D"/* > "\$D/SHA256SUMS"
find $INSTALL_DIR/backups -maxdepth 1 -name 'nightly-*' -type d -mtime +14 -exec rm -rf {} +
EOF
  put_file -m 0644 /etc/logrotate.d/worksuite <<EOF
$INSTALL_DIR/shared/writable/logs/*.log /var/log/worksuite-backup.log {
    weekly
    rotate 8
    compress
    delaycompress
    missingok
    notifempty
    su $WEB_USER $WEB_USER
    create 0640 $WEB_USER $WEB_USER
}
EOF
  ok "02:30 every night → $INSTALL_DIR/backups (14 days)"
}

# ---------------------------------------------------------------------------
# 13. Firewall and TLS
# ---------------------------------------------------------------------------
secure_server() {
  step "Firewall and TLS"
  [[ $DRY_RUN -eq 1 ]] && { info "(dry run)"; return 0; }
  if [[ $FIREWALL -eq 1 ]]; then
    if [[ $FAMILY == debian ]]; then
      ufw allow OpenSSH >>"$LOG" 2>&1; ufw limit OpenSSH >>"$LOG" 2>&1 || true
      ufw allow 'Nginx Full' >>"$LOG" 2>&1; ufw --force enable >>"$LOG" 2>&1
      ok "UFW: SSH (rate-limited), 80, 443"
    else
      systemctl enable --now firewalld >>"$LOG" 2>&1 || true
      firewall-cmd --permanent --add-service=ssh --add-service=http --add-service=https >>"$LOG" 2>&1 && firewall-cmd --reload >>"$LOG" 2>&1
      ok "firewalld: SSH, 80, 443"
    fi
  fi
  systemctl enable --now fail2ban >>"$LOG" 2>&1 || true
  if [[ $SSL -eq 1 ]]; then
    if certbot --nginx -d "$DOMAIN" -m "$ADMIN_EMAIL" --agree-tos -n --redirect >>"$LOG" 2>&1; then ok "Let's Encrypt certificate for $DOMAIN (renews itself)"
    else warn "TLS could not be issued yet (is DNS pointing here?). Later: certbot --nginx -d $DOMAIN -m $ADMIN_EMAIL --agree-tos --redirect"; fi
  else
    warn "TLS skipped (--no-ssl)"
  fi
}

# ---------------------------------------------------------------------------
# 14. Health and summary
# ---------------------------------------------------------------------------
health_check() {
  step "Health check"
  [[ $DRY_RUN -eq 1 ]] && { info "(dry run)"; return 0; }
  if curl -fsS --max-time 10 -H "Host: $DOMAIN" "http://127.0.0.1/api/health" >/dev/null 2>&1 || curl -fsS --max-time 10 "https://$DOMAIN/api/health" >/dev/null 2>&1; then ok "GET /api/health"
  else warn "/api/health did not answer — see $INSTALL_DIR/shared/writable/logs"; fi
  for s in "$FPM_SERVICE" nginx "$REDIS_SERVICE" worksuite-updater; do systemctl is-active --quiet "$s" && ok "$s active" || warn "$s is not active"; done
  [[ $EXTERNAL_DB -eq 1 ]] || { systemctl is-active --quiet mariadb && ok "mariadb active" || warn "mariadb is not active"; }
  # Read it whole first: with pipefail, grep -q stopping early kills spark with SIGPIPE and the check fails.
  local lic; lic=$(spark license:status 2>/dev/null || true)
  grep -qE '^effective +ACTIVE' <<<"$lic" && ok "Licence active" || warn "The licence needs attention: sudo -u $WEB_USER $PHP_BIN $INSTALL_DIR/current/spark license:status"
}

summary() {
  [[ $DRY_RUN -eq 1 ]] && { printf '\n%sDry run complete — nothing was changed.%s\n' "$B" "$N"; return 0; }
  put_file -m 0600 "$CRED_FILE" <<EOF
WorkSuite Server — installed $(date)
Address        : https://$DOMAIN/
Version        : $REL_VERSION (build $REL_BUILD), ${EDITION^} edition, $CHANNEL channel
Administrator  : $ADMIN_EMAIL
Temp password  : $ADMIN_PASS   (change it at first sign-in; turn on two-factor)
Database       : $DB_USER@$DB_HOST:$DB_PORT/$DB_NAME  password: $DB_PASS
Install root   : $INSTALL_DIR  (current → $(readlink -f "$INSTALL_DIR/current"))
Configuration  : $INSTALL_DIR/shared/.env
Licence        : Admin Center › System › License
Updates        : Admin Center › System › Updates (agent: worksuite-updater)
Backups        : $INSTALL_DIR/backups (02:30 nightly)
Install log    : $LOG
EOF
  cat <<EOF

${G}${B}WorkSuite Server is installed.${N}

  Open        ${B}https://$DOMAIN/${N}
  Sign in     $ADMIN_EMAIL — the temporary password is in $CRED_FILE (root only)
  Edition     ${EDITION^} · $REL_VERSION build $REL_BUILD
  Licence     Admin Center › System › License
  Updates     Admin Center › System › Updates

  Next: set up outgoing mail, turn on two-factor, and keep $CRED_FILE somewhere safe before deleting it.
EOF
  if [[ $OFFLINE -eq 1 ]]; then
    cat <<EOF

  ${B}Offline licence:${N} take /root/worksuite-request.wsr to a computer with internet, upload it with your
  licence key at $WS_DOWNLOAD_CENTER/offline-activation, bring back the .wsl file, and import it in
  Admin Center › System › License (or: sudo -u $WEB_USER $PHP_BIN $INSTALL_DIR/current/spark license:import FILE.wsl).
EOF
  fi
}

main() {
  parse_args "$@"
  printf '%sWorkSuite Server installer %s%s\n' "$B" "$INSTALLER_VERSION" "$N"
  precheck
  collect_inputs
  check_license
  install_packages
  prepare_layout
  fetch_package
  extract_package
  setup_database
  write_env
  configure_php
  configure_nginx
  activate_release
  install_services
  install_cron
  install_backup
  secure_server
  health_check
  summary
  [[ -n "${WORK:-}" && -d "${WORK:-}" ]] && rm -rf "$WORK"
}

main "$@"
