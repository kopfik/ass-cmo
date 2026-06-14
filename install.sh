#!/bin/sh
set -eu

APP_NAME="ASS-CMO"
CORE_SERVICES="postgres adminer php nginx"
ENV_FILE="config.local/.env"
ROOT_ENV_FILE=".env"

setup_colors() {
  if [ -t 1 ] && [ -z "${NO_COLOR:-}" ] && command -v tput >/dev/null 2>&1 && [ "$(tput colors 2>/dev/null || printf 0)" -ge 8 ]; then
    bold="$(tput bold)"
    reset="$(tput sgr0)"
    green="$(tput setaf 2)"
    yellow="$(tput setaf 3)"
    blue="$(tput setaf 4)"
    red="$(tput setaf 1)"
    cyan="$(tput setaf 6)"
    magenta="$(tput setaf 5)"
  else
    bold=""
    reset=""
    green=""
    yellow=""
    blue=""
    red=""
    cyan=""
    magenta=""
  fi
}

setup_colors

info() {
  printf '\n%s==>%s %s\n' "${green}${bold}" "$reset" "$*"
}

detail() {
  printf '%s::%s %s\n' "${cyan}${bold}" "$reset" "$*" >&2
}

value() {
  printf '%s  ->%s %s\n' "${blue}${bold}" "$reset" "$*" >&2
}

warn() {
  printf '%swarning:%s %s\n' "${yellow}${bold}" "$reset" "$*" >&2
}

die() {
  printf '%serror:%s %s\n' "${red}${bold}" "$reset" "$*" >&2
  exit 1
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "$1 is required"
}

prompt_line() {
  printf '%s==>%s %s' "${magenta}${bold}" "$reset" "$*" >&2
}

ask_default() {
  prompt="$1"
  default="$2"

  detail "$prompt"
  value "default: $default"
  prompt_line "value [$default]: "
  IFS= read -r value || value=""
  if [ -z "$value" ]; then
    value="$default"
  fi
  printf '%s' "$value"
}

ask_optional() {
  prompt="$1"
  default="$2"

  detail "$prompt"
  if [ -n "$default" ]; then
    value "default: $default"
    prompt_line "value [$default]: "
  else
    value "optional; leave empty to let local SSH/RDP clients use the current workstation/user context"
    prompt_line "value: "
  fi

  IFS= read -r value || value=""
  if [ -z "$value" ]; then
    value="$default"
  fi
  printf '%s' "$value"
}

ask_required() {
  prompt="$1"
  value=""

  while [ -z "$value" ]; do
    detail "$prompt"
    prompt_line "value: "
    IFS= read -r value || value=""
    if [ -z "$value" ]; then
      warn "This value is required."
    fi
  done

  printf '%s' "$value"
}

ask_yes_no_default_yes() {
  prompt="$1"
  detail "$prompt"
  prompt_line "answer [Y/n]: "
  IFS= read -r answer || answer=""
  case "$answer" in
    n|N|no|NO|No) return 1 ;;
    *) return 0 ;;
  esac
}

rand_b64() {
  openssl rand -base64 "$1"
}

set_env() {
  key="$1"
  value="$2"
  file="$3"

  escaped="$(printf '%s' "$value" | sed 's/[\/&]/\\&/g')"

  if grep -q "^${key}=" "$file"; then
    sed -i "s|^${key}=.*|${key}=${escaped}|" "$file"
  else
    printf '%s=%s\n' "$key" "$value" >> "$file"
  fi
}

get_env() {
  key="$1"
  file="$2"
  grep "^${key}=" "$file" | head -n1 | cut -d= -f2- || true
}

list_letsencrypt_cert_names() {
  if [ -d /etc/letsencrypt/live ]; then
    find /etc/letsencrypt/live -mindepth 1 -maxdepth 1 -type d 2>/dev/null | sed 's|.*/||' | grep -v '^README$' | sort
  fi
}

default_instance_hostname_for_cert() {
  cert_name="$1"
  cert="/etc/letsencrypt/live/$cert_name/fullchain.pem"

  if [ -f "$cert" ]; then
    names="$(openssl x509 -in "$cert" -noout -ext subjectAltName 2>/dev/null | sed -n 's/.*DNS://gp' | tr ',' '\n' | sed 's/^ *//; s/ *$//' || true)"

    wildcard="$(printf '%s\n' "$names" | grep '^\*\.' | head -n1 || true)"
    if [ -n "$wildcard" ]; then
      printf 'ass-cmo.%s' "${wildcard#\*.}"
      return 0
    fi

    exact="$(printf '%s\n' "$names" | grep '^ass-cmo\.' | head -n1 || true)"
    if [ -n "$exact" ]; then
      printf '%s' "$exact"
      return 0
    fi
  fi

  if printf '%s' "$cert_name" | grep -q '\.'; then
    printf 'ass-cmo.%s' "$cert_name"
  else
    printf 'ass-cmo.example.com'
  fi
}

choose_letsencrypt_cert() {
  certs="$(list_letsencrypt_cert_names)"
  count="$(printf '%s\n' "$certs" | sed '/^$/d' | wc -l | tr -d ' ')"

  detail "Detected Let's Encrypt certificate names in /etc/letsencrypt/live"

  if [ "$count" -eq 0 ]; then
    warn "No Let's Encrypt certificates found in /etc/letsencrypt/live."
    printf ''
    return 0
  fi

  if [ "$count" -eq 1 ]; then
    cert_name="$(printf '%s\n' "$certs" | sed '/^$/d' | head -n1)"
    value "$cert_name"
    printf '%s' "$cert_name"
    return 0
  fi

  i=1
  printf '%s\n' "$certs" | sed '/^$/d' | while IFS= read -r cert_name; do
    value "$i) $cert_name"
    i=$((i + 1))
  done

  choice=""
  while :; do
    prompt_line "select certificate [1-$count]: "
    IFS= read -r choice || choice=""
    case "$choice" in
      ''|*[!0-9]*)
        warn "Enter a number from 1 to $count."
        continue
        ;;
    esac

    if [ "$choice" -ge 1 ] && [ "$choice" -le "$count" ]; then
      printf '%s\n' "$certs" | sed '/^$/d' | sed -n "${choice}p"
      return 0
    fi

    warn "Enter a number from 1 to $count."
  done
}


default_instance_hostname() {
  if [ -d /etc/letsencrypt/live ]; then
    for dir in /etc/letsencrypt/live/*; do
      [ -d "$dir" ] || continue
      cert_name="$(basename "$dir")"
      [ "$cert_name" = "README" ] && continue

      cert="/etc/letsencrypt/live/$cert_name/fullchain.pem"
      [ -f "$cert" ] || continue

      names="$(openssl x509 -in "$cert" -noout -ext subjectAltName 2>/dev/null | sed -n 's/.*DNS://gp' | tr ',' '\n' | sed 's/^ *//; s/ *$//' || true)"

      wildcard="$(printf '%s\n' "$names" | grep '^\*\.' | head -n1 || true)"
      if [ -n "$wildcard" ]; then
        printf 'ass-cmo.%s' "${wildcard#\*.}"
        return 0
      fi

      exact="$(printf '%s\n' "$names" | grep '^ass-cmo\.' | head -n1 || true)"
      if [ -n "$exact" ]; then
        printf '%s' "$exact"
        return 0
      fi

      if printf '%s' "$cert_name" | grep -q '\.'; then
        printf 'ass-cmo.%s' "$cert_name"
        return 0
      fi
    done
  fi

  printf 'ass-cmo.example.com'
}

default_adminer_url() {
  host="$1"
  suffix="${host#*.}"

  if [ "$suffix" != "$host" ] && [ -n "$suffix" ]; then
    printf 'https://adminer.%s/' "$suffix"
  else
    printf 'https://adminer.%s/' "$host"
  fi
}

cert_covers_host() {
  cert_name="$1"
  host="$2"
  cert="/etc/letsencrypt/live/$cert_name/fullchain.pem"

  [ -f "$cert" ] || return 1

  names="$(openssl x509 -in "$cert" -noout -subject -ext subjectAltName 2>/dev/null | sed -n 's/.*DNS://gp' | tr ',' '\n' | sed 's/^ *//; s/ *$//' || true)"

  printf '%s\n' "$names" | while IFS= read -r name; do
    [ -n "$name" ] || continue

    if [ "$name" = "$host" ]; then
      exit 0
    fi

    case "$name" in
      \*.*)
        suffix="${name#\*.}"
        case "$host" in
          *."$suffix") exit 0 ;;
        esac
        ;;
    esac
  done

  # POSIX sh cannot directly read the while-exit status reliably through all shells here,
  # so repeat with grep-friendly checks below.
  if printf '%s\n' "$names" | grep -Fxq "$host"; then
    return 0
  fi

  printf '%s\n' "$names" | while IFS= read -r name; do
    case "$name" in
      \*.*)
        suffix="${name#\*.}"
        case "$host" in
          *."$suffix") printf matched; exit 0 ;;
        esac
        ;;
    esac
  done | grep -q matched
}

default_tls_cert_name() {
  host="$1"

  if [ -d "/etc/letsencrypt/live/$host" ] && cert_covers_host "$host" "$host"; then
    printf '%s' "$host"
    return 0
  fi

  if [ -d /etc/letsencrypt/live ]; then
    for dir in /etc/letsencrypt/live/*; do
      [ -d "$dir" ] || continue
      cert_name="$(basename "$dir")"
      [ "$cert_name" = "README" ] && continue

      if cert_covers_host "$cert_name" "$host"; then
        printf '%s' "$cert_name"
        return 0
      fi
    done
  fi

  candidate="$host"
  while printf '%s' "$candidate" | grep -q '\.'; do
    candidate="${candidate#*.}"
    if [ -d "/etc/letsencrypt/live/$candidate" ]; then
      printf '%s' "$candidate"
      return 0
    fi
  done

  first_cert="$(find /etc/letsencrypt/live -mindepth 1 -maxdepth 1 -type d 2>/dev/null | sed 's|.*/||' | grep -v '^README$' | head -n1 || true)"
  if [ -n "$first_cert" ]; then
    printf '%s' "$first_cert"
    return 0
  fi

  printf '%s' "$host"
}

check_tls_cert_files() {
  cert_name="$1"

  if [ ! -f "/etc/letsencrypt/live/$cert_name/fullchain.pem" ] || [ ! -f "/etc/letsencrypt/live/$cert_name/privkey.pem" ]; then
    warn "TLS certificate files were not found for: $cert_name"
    warn "Expected:"
    warn "  /etc/letsencrypt/live/$cert_name/fullchain.pem"
    warn "  /etc/letsencrypt/live/$cert_name/privkey.pem"
    return 1
  fi

  return 0
}

ensure_dhparam() {
  dhparam_file="config.local/nginx/dhparam2048.pem"

  if [ -f "$dhparam_file" ]; then
    value "dhparam: $dhparam_file already exists"
    return 0
  fi

  info "Generating nginx DH parameters"
  warn "This can take a while on slower machines."
  openssl dhparam -out "$dhparam_file" 2048
  chmod 600 "$dhparam_file"
  value "dhparam: $dhparam_file"
}

list_letsencrypt_certs() {
  found=0

  detail "Detected Let's Encrypt certificate names in /etc/letsencrypt/live"

  if [ -d /etc/letsencrypt/live ]; then
    for dir in /etc/letsencrypt/live/*; do
      if [ -d "$dir" ]; then
        found=1
        value "$(basename "$dir")"
      fi
    done
  fi

  if [ "$found" -eq 0 ]; then
    warn "No Let's Encrypt certificates found in /etc/letsencrypt/live."
  fi
}

print_install_credentials() {
  env_file="$1"

  info "First-login credentials"
  warn "These values are stored in $env_file and are printed for initial setup convenience. Terminal scrollback may contain them."
  warn "Internal service tokens are not printed here. Open $env_file if you intentionally need them."

  detail "Adminer / PostgreSQL application login"
  value "server: postgres"
  value "database: $(get_env POSTGRES_DB "$env_file")"
  value "user: $(get_env POSTGRES_USER "$env_file")"
  value "password: $(get_env POSTGRES_PASSWORD "$env_file")"

  detail "Dashboard read-only PostgreSQL login"
  value "database: $(get_env POSTGRES_DB "$env_file")"
  value "user: $(get_env POSTGRES_DASHBOARD_USER "$env_file")"
  value "password: $(get_env POSTGRES_DASHBOARD_PASSWORD "$env_file")"

  detail "Grafana admin login"
  value "user: $(get_env GRAFANA_ADMIN_USER "$env_file")"
  value "password: $(get_env GRAFANA_ADMIN_PASSWORD "$env_file")"

  detail "InfluxDB initial admin login for TIM/TIGM overlays"
  value "user: $(get_env INFLUXDB_ADMIN_USER "$env_file")"
  value "password: $(get_env INFLUXDB_ADMIN_PASSWORD "$env_file")"
}

prepare_env_file() {
  mkdir -p config.local
  chmod 700 config.local

  if [ -f "$ROOT_ENV_FILE" ] && [ ! -L "$ROOT_ENV_FILE" ] && [ -f "$ENV_FILE" ]; then
    die "Both .env and config.local/.env exist. Merge them manually, remove root .env, then rerun installer."
  fi

  if [ -f "$ROOT_ENV_FILE" ] && [ ! -L "$ROOT_ENV_FILE" ] && [ ! -f "$ENV_FILE" ]; then
    info "Moving existing .env to config.local/.env"
    mv "$ROOT_ENV_FILE" "$ENV_FILE"
  fi

  if [ ! -f "$ENV_FILE" ]; then
    cp .env.example "$ENV_FILE"
  fi

  chmod 600 "$ENV_FILE"

  rm -f "$ROOT_ENV_FILE"
  ln -s "$ENV_FILE" "$ROOT_ENV_FILE"
}

set_env_if_missing_or_weak() {
  key="$1"
  value="$2"
  file="$3"

  current="$(get_env "$key" "$file")"
  if [ -z "$current" ] || [ "$current" = "changeme" ] || [ "$current" = "change-me" ] || printf '%s' "$current" | grep -q '^change-this'; then
    set_env "$key" "$value" "$file"
  fi
}

copy_dir_contents_if_empty() {
  src="$1"
  dst="$2"

  [ -d "$src" ] || die "Missing source directory: $src"
  mkdir -p "$dst"

  existing="$(find "$dst" -mindepth 1 -maxdepth 1 ! -name '.gitkeep' 2>/dev/null | head -n1)"
  if [ -z "$existing" ]; then
    cp -a "$src"/. "$dst"/
  else
    info "$dst already contains files; not overwriting."
  fi
}

copy_file_if_missing() {
  src="$1"
  dst="$2"

  [ -f "$src" ] || die "Missing source file: $src"
  if [ ! -f "$dst" ]; then
    cp "$src" "$dst"
  fi
}

replace_nginx_placeholders() {
  ass_host="$1"
  adminer_host="$2"
  cert_name="$3"

  [ -d config.local/nginx ] || return 0

  find config.local/nginx -type f -print | while IFS= read -r file; do
    sed -i \
      -e "s|ass-cmo.example.com|${ass_host}|g" \
      -e "s|adminer.example.com|${adminer_host}|g" \
      -e "s|/etc/letsencrypt/live/example.com/|/etc/letsencrypt/live/${cert_name}/|g" \
      "$file"
  done
}

sync_dashboard_view_templates() {
  env_file="$1"

  base_url="$(get_env ASSCMO_BASE_URL "$env_file")"
  ssh_user="$(get_env ASSCMO_DASHBOARD_SSH_USER "$env_file")"
  linux_version=""

  if [ -f agents/linux/VERSION ]; then
    linux_version="$(cat agents/linux/VERSION)"
  fi

  if [ -z "$ssh_user" ]; then
    ssh_user="$(id -un 2>/dev/null || printf root)"
  fi

  find config.local/dashboard-views -type f -name '*.sql' -print | while IFS= read -r file; do
    sed -i \
      -e "s|__ASSCMO_BASE_URL__|${base_url}|g" \
      -e "s|__ASSCMO_DASHBOARD_SSH_USER__|${ssh_user}|g" \
      -e "s|__ASSCMO_LINUX_AGENT_VERSION__|${linux_version}|g" \
      "$file"
  done
}

wait_for_postgres() {
  tries=30
  while [ "$tries" -gt 0 ]; do
    if docker exec ass-postgres pg_isready >/dev/null 2>&1; then
      return 0
    fi
    tries=$((tries - 1))
    sleep 2
  done

  return 1
}

create_dashboard_user() {
  env_file="$1"

  dashboard_user="$(get_env POSTGRES_DASHBOARD_USER "$env_file")"
  dashboard_pw="$(get_env POSTGRES_DASHBOARD_PASSWORD "$env_file")"
  postgres_user="$(get_env POSTGRES_USER "$env_file")"
  postgres_db="$(get_env POSTGRES_DB "$env_file")"

  if [ -z "$dashboard_user" ] || [ -z "$dashboard_pw" ] || [ -z "$postgres_user" ] || [ -z "$postgres_db" ]; then
    warn "Missing DB env values; skipping dashboard read-only user setup."
    return 0
  fi

  if [ ! -f database/scripts/002_dashboard_readonly_user.sql ]; then
    warn "Missing database/scripts/002_dashboard_readonly_user.sql; skipping dashboard read-only user setup."
    return 0
  fi

  if wait_for_postgres; then
    docker exec -i ass-postgres psql -U "$postgres_user" -d "$postgres_db" -v dashboard_user="$dashboard_user" -v dashboard_password="'$dashboard_pw'" < database/scripts/002_dashboard_readonly_user.sql
  else
    warn "PostgreSQL did not become ready in time; run dashboard read-only user SQL manually later."
  fi
}

apply_database_schema() {
  env_file="$1"

  postgres_user="$(get_env POSTGRES_USER "$env_file")"
  postgres_db="$(get_env POSTGRES_DB "$env_file")"

  if [ -z "$postgres_user" ] || [ -z "$postgres_db" ]; then
    die "Missing DB env values; cannot initialize schema."
  fi

  if ! wait_for_postgres; then
    die "PostgreSQL did not become ready in time; cannot initialize schema."
  fi

  info "Applying database schema from database/init/"
  for sql_file in database/init/*.sql; do
    [ -f "$sql_file" ] || continue
    info "Applying schema file: $sql_file"
    docker exec -i ass-postgres psql -v ON_ERROR_STOP=1 -U "$postgres_user" -d "$postgres_db" < "$sql_file"
  done
}

[ -f compose.yml ] || die "Run this installer from the ASS-CMO repository root."

info "$APP_NAME core installer"

need_cmd docker
need_cmd openssl
docker compose version >/dev/null 2>&1 || die "Docker Compose plugin is required."

if [ "$(id -u)" -ne 0 ]; then
  die "This installer must be run as root. It needs access to /etc/letsencrypt, Docker, file ownership and service configuration."
fi

env_existed=0
if [ -f "$ENV_FILE" ] || [ -f "$ROOT_ENV_FILE" ]; then
  env_existed=1
fi

prepare_env_file

if [ "$env_existed" -eq 1 ]; then
  info "$ENV_FILE exists."
  if ask_yes_no_default_yes "Use existing $ENV_FILE and only fill missing/weak values?"; then
    use_existing_env=1
  else
    die "Refusing to overwrite existing $ENV_FILE. Move it away first if you want a fresh install."
  fi
else
  use_existing_env=0
fi

if [ "$use_existing_env" -eq 1 ] && { [ "$(get_env ASSCMO_INSTANCE_NAME "$ENV_FILE")" = "ass-cmo.example.com" ] || [ -z "$(get_env ASSCMO_INSTANCE_NAME "$ENV_FILE")" ]; }; then
  use_existing_env=0
fi

if [ "$use_existing_env" -eq 0 ]; then
  tls_cert_name="$(choose_letsencrypt_cert)"
  if [ -n "$tls_cert_name" ]; then
    instance_name="$(ask_default "Enter ASS-CMO instance hostname" "$(default_instance_hostname_for_cert "$tls_cert_name")")"
    detail "Using selected Let's Encrypt certificate"
    value "$tls_cert_name"
  else
    instance_name="$(ask_default "Enter ASS-CMO instance hostname" "$(default_instance_hostname)")"
    tls_cert_name="$(default_tls_cert_name "$instance_name")"
    detail "Using detected Let's Encrypt certificate"
    value "$tls_cert_name"
  fi
  adminer_url="$(ask_default "Enter Adminer public URL" "$(default_adminer_url "$instance_name")")"
  postgres_db="$(ask_default "Enter PostgreSQL database name" "inventory_db")"
  postgres_user="$(ask_default "Enter PostgreSQL application user" "asscmo")"
  dashboard_user="$(ask_default "Enter dashboard read-only DB user" "ass_dashboarder")"
  grafana_user="$(ask_default "Enter Grafana admin user" "admin")"
  ssh_user="$(ask_optional "Enter default SSH user for dashboard links" "")"

  cp .env.example "$ENV_FILE"

  base_url="https://${instance_name}"

  set_env ASSCMO_INSTANCE_NAME "$instance_name" "$ENV_FILE"
  set_env ASSCMO_BASE_URL "$base_url" "$ENV_FILE"
  set_env ASSCMO_ADMINER_URL "$adminer_url" "$ENV_FILE"
  set_env ASSCMO_TLS_CERT_NAME "$tls_cert_name" "$ENV_FILE"
  set_env POSTGRES_DB "$postgres_db" "$ENV_FILE"
  set_env POSTGRES_USER "$postgres_user" "$ENV_FILE"
  set_env POSTGRES_DASHBOARD_USER "$dashboard_user" "$ENV_FILE"
  set_env GRAFANA_ADMIN_USER "$grafana_user" "$ENV_FILE"
  set_env ASSCMO_DASHBOARD_SSH_USER "$ssh_user" "$ENV_FILE"
else
  if [ -z "$(get_env ASSCMO_INSTANCE_NAME "$ENV_FILE")" ]; then
    tls_cert_name_existing="$(choose_letsencrypt_cert)"
    if [ -n "$tls_cert_name_existing" ]; then
      instance_name="$(ask_default "Enter ASS-CMO instance hostname" "$(default_instance_hostname_for_cert "$tls_cert_name_existing")")"
    else
      instance_name="$(ask_default "Enter ASS-CMO instance hostname" "$(default_instance_hostname)")"
    fi
    set_env ASSCMO_INSTANCE_NAME "$instance_name" "$ENV_FILE"
  fi

  instance_name="$(get_env ASSCMO_INSTANCE_NAME "$ENV_FILE")"

  if [ -z "$(get_env ASSCMO_BASE_URL "$ENV_FILE")" ] || [ "$(get_env ASSCMO_BASE_URL "$ENV_FILE")" = "https://ass-cmo.example.com" ]; then
    set_env ASSCMO_BASE_URL "https://${instance_name}" "$ENV_FILE"
  fi

  if [ -z "$(get_env ASSCMO_ADMINER_URL "$ENV_FILE")" ]; then
    adminer_url="$(ask_default "Enter Adminer public URL" "$(default_adminer_url "$instance_name")")"
    set_env ASSCMO_ADMINER_URL "$adminer_url" "$ENV_FILE"
  fi

  if [ -z "$(get_env ASSCMO_TLS_CERT_NAME "$ENV_FILE")" ]; then
    tls_cert_name="$(choose_letsencrypt_cert)"
    if [ -z "$tls_cert_name" ]; then
      tls_cert_name="$(ask_default "Enter Let's Encrypt certificate name" "$(default_tls_cert_name "$instance_name")")"
    else
      detail "Using selected Let's Encrypt certificate"
      value "$tls_cert_name"
    fi
    set_env ASSCMO_TLS_CERT_NAME "$tls_cert_name" "$ENV_FILE"
  fi

  set_env_if_missing_or_weak POSTGRES_DB "inventory_db" "$ENV_FILE"
  set_env_if_missing_or_weak POSTGRES_USER "asscmo" "$ENV_FILE"
  set_env_if_missing_or_weak POSTGRES_DASHBOARD_USER "ass_dashboarder" "$ENV_FILE"
fi

info "Selected installation values"
value "instance: $(get_env ASSCMO_INSTANCE_NAME "$ENV_FILE")"
value "base URL: $(get_env ASSCMO_BASE_URL "$ENV_FILE")"
value "Adminer URL: $(get_env ASSCMO_ADMINER_URL "$ENV_FILE")"
value "TLS certificate name: $(get_env ASSCMO_TLS_CERT_NAME "$ENV_FILE")"
check_tls_cert_files "$(get_env ASSCMO_TLS_CERT_NAME "$ENV_FILE")" || warn "Nginx will not start until the certificate name/path is corrected."
value "runtime env: $ENV_FILE"
value "root env symlink: $ROOT_ENV_FILE -> $ENV_FILE"

info "Generating missing secrets"
set_env_if_missing_or_weak ASSCMO_ENROLLMENT_PEPPER "$(rand_b64 48)" "$ENV_FILE"
set_env_if_missing_or_weak ASSCMO_ENROLLMENT_APPROVE_TOKEN "$(rand_b64 48)" "$ENV_FILE"
set_env_if_missing_or_weak POSTGRES_PASSWORD "$(rand_b64 32)" "$ENV_FILE"
set_env_if_missing_or_weak POSTGRES_DASHBOARD_PASSWORD "$(rand_b64 32)" "$ENV_FILE"
set_env_if_missing_or_weak GRAFANA_ADMIN_PASSWORD "$(rand_b64 32)" "$ENV_FILE"
set_env_if_missing_or_weak INFLUXDB_ADMIN_PASSWORD "$(rand_b64 32)" "$ENV_FILE"
set_env_if_missing_or_weak INFLUXDB_ADMIN_TOKEN "$(rand_b64 48)" "$ENV_FILE"
set_env_if_missing_or_weak INFLUX_OPERATOR_TOKEN "$(rand_b64 48)" "$ENV_FILE"
chmod 600 "$ENV_FILE"

info "Preparing local directories and files"
mkdir -p config.local/backups config.local/dashboard-views config.local/grafana/dashboards config.local/grafana/provisioning config.local/influxdb/config config.local/mosquitto config.local/nginx config.local/scripts config.local/telegraf config.local/branding/logo config.local/branding/icons
cp -n config.example/branding/logo/* config.local/branding/logo/ 2>/dev/null || true

copy_dir_contents_if_empty config.example/nginx config.local/nginx
ensure_dhparam
adminer_hostname="$(get_env ASSCMO_ADMINER_URL "$ENV_FILE" | sed -E "s|^https?://||; s|/.*$||")"
replace_nginx_placeholders "$(get_env ASSCMO_INSTANCE_NAME "$ENV_FILE")" "$adminer_hostname" "$(get_env ASSCMO_TLS_CERT_NAME "$ENV_FILE")"
cp -n config.example/dashboard-views/*.sql config.local/dashboard-views/ 2>/dev/null || true
sync_dashboard_view_templates "$ENV_FILE"

[ -f config.local/sites.json ] || printf '{}\n' > config.local/sites.json

info "Ensuring Docker network exists"
docker network inspect ass-net >/dev/null 2>&1 || docker network create ass-net >/dev/null

if ask_yes_no_default_yes "Print first-login credentials now?"; then
  print_install_credentials "$ENV_FILE"
fi

if ask_yes_no_default_yes "Start ASS-CMO core stack now?"; then
  check_tls_cert_files "$(get_env ASSCMO_TLS_CERT_NAME "$ENV_FILE")" || die "Refusing to start nginx with missing TLS certificate files. Re-run installer or edit $ENV_FILE."
  info "Starting core stack: $CORE_SERVICES"
  docker compose --env-file "$ENV_FILE" up -d $CORE_SERVICES

  info "Initializing database schema"
  apply_database_schema "$ENV_FILE"

  info "Creating/updating dashboard read-only database user"
  create_dashboard_user "$ENV_FILE"
else
  warn "Core stack was not started."
fi

info "ASS-CMO core installer finished"

detail "Core services"
value "docker compose --env-file config.local/.env ps"

detail "Important local files"
value "config.local/.env"
value ".env -> config.local/.env"
value "config.local/nginx/"
value "config.local/dashboard-views/"

detail "Dashboard URL"
value "https://$(get_env ASSCMO_INSTANCE_NAME "$ENV_FILE")/"

detail "Linux URI handlers"
value "curl -fsSL https://$(get_env ASSCMO_INSTANCE_NAME "$ENV_FILE")/agents/handlers/linux/install-ass-cmo-uri-handlers.sh | sh"

detail "Windows URI handlers"
value "Invoke-WebRequest -UseBasicParsing \"https://$(get_env ASSCMO_INSTANCE_NAME "$ENV_FILE")/agents/handlers/windows/install-ass-cmo-uri-handlers.ps1\" -OutFile \"\$env:TEMP\\install-ass-cmo-uri-handlers.ps1\"; powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"\$env:TEMP\\install-ass-cmo-uri-handlers.ps1\""

detail "Notes"
warn "Review config.local/nginx/ before exposing this publicly."
warn "TLS certificates and DNS are intentionally not automated by this installer."
warn "Grafana, InfluxDB, Telegraf, Mosquitto and TIM/TIGM overlays are not installed by this core installer."
