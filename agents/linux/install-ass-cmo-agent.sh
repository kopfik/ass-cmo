#!/bin/sh
set -eu

BASE_URL="${ASSCMO_BASE_URL:-}"
CONFIG_FILE="/etc/ass-cmo/agent.conf"
DEFAULT_AGENT_NAME="linux-shell"
DEFAULT_AGENT_CHANNEL="stable"
DEFAULT_POLL_INTERVAL=5
DEFAULT_ENROLL_TIMEOUT=1800

# TTY-aware color codes. Empty when stdout is not a terminal so piped/logged
# output stays readable without escape sequences.
if [ -t 1 ]; then
    _ESC="$(printf '\033')"
    TTY_BOLD="${_ESC}[1m"
    TTY_RED="${_ESC}[1;31m"
    TTY_GREEN="${_ESC}[1;32m"
    TTY_YELLOW="${_ESC}[1;33m"
    TTY_BLUE="${_ESC}[1;34m"
    TTY_CYAN="${_ESC}[0;36m"
    TTY_RESET="${_ESC}[0m"
    unset _ESC
else
    TTY_BOLD=''
    TTY_RED=''
    TTY_GREEN=''
    TTY_YELLOW=''
    TTY_BLUE=''
    TTY_CYAN=''
    TTY_RESET=''
fi

# Console output helpers in a compact pacman/makepkg-like style. They emit plain
# text automatically when stdout is not a TTY because the color variables above
# are empty in that case. Messages are passed as %s arguments, never as the
# format string, so values cannot be interpreted as printf directives.
log_section() {
    printf '%s\n' "${TTY_BLUE}::${TTY_RESET} ${TTY_BOLD}$1${TTY_RESET}"
}

log_step() {
    printf '%s\n' "${TTY_GREEN}==>${TTY_RESET} ${TTY_BOLD}$1${TTY_RESET}"
}

log_info() {
    printf '%s\n' "  ${TTY_BLUE}->${TTY_RESET} $1"
}

log_ok() {
    printf '%s\n' "${TTY_GREEN}==>${TTY_RESET} ${TTY_GREEN}$1${TTY_RESET}"
}

log_wait() {
    printf '%s\n' "${TTY_YELLOW}==>${TTY_RESET} $1"
}

log_warn() {
    printf '%s\n' "${TTY_YELLOW}==> WARNING:${TTY_RESET} $1" >&2
}

log_error() {
    printf '%s\n' "${TTY_RED}==> ERROR:${TTY_RESET} $1" >&2
}

usage() {
    echo "Usage: $0 --base-url URL" >&2
}

need_cmd() {
    command -v "$1" >/dev/null 2>&1 || {
        log_error "$1 not found"
        exit 1
    }
}

json_get_string() {
    key="$1"
    file="$2"

    jq -r --arg key "$key" 'if has($key) and (.[$key] | type == "string") then .[$key] else empty end' "$file" 2>/dev/null || true
}

json_get_int() {
    key="$1"
    file="$2"

    jq -r --arg key "$key" 'if has($key) and (.[$key] | type == "number") then .[$key] else empty end' "$file" 2>/dev/null || true
}

json_int_or_default() {
    key="$1"
    file="$2"
    default="$3"

    value="$(json_get_int "$key" "$file")"
    if [ -n "$value" ]; then
        printf '%s' "$value"
    else
        printf '%s' "$default"
    fi
}

shell_double_quote_escape() {
    value="$1"
    value=$(printf '%s' "$value" | sed 's/\\/\\\\/g')
    value=$(printf '%s' "$value" | sed 's/"/\\"/g')
    value=$(printf '%s' "$value" | sed 's/\$/\\$/g')
    value=$(printf '%s' "$value" | sed 's/`/\\`/g')
    printf '%s' "$value"
}

enroll_uid() {
    uid="$(cat /etc/machine-id 2>/dev/null | tr -d '\r\n' || true)"
    if [ -n "$uid" ]; then
        printf '%s' "$uid"
        return 0
    fi

    hostname 2>/dev/null | tr -d '\r\n'
}

enroll_hostname() {
    uname -n | tr '[:upper:]' '[:lower:]' | xargs
}

enroll_fqdn() {
    fqdn="$(hostname -f 2>/dev/null | tr '[:upper:]' '[:lower:]' | xargs || true)"
    if [ -n "$fqdn" ]; then
        printf '%s' "$fqdn"
    else
        enroll_hostname
    fi
}

write_agent_config() {
    agent_secret="$1"
    config_tmp="$2"
    escaped_base_url="$(shell_double_quote_escape "$BASE_URL")"
    escaped_agent_secret="$(shell_double_quote_escape "$agent_secret")"

    umask 077
    cat > "$config_tmp" <<EOF
# ASS-CMO Linux agent configuration.
# Written by the ASS-CMO Linux installer after successful enrollment.

ASSCMO_BASE_URL="$escaped_base_url"
ASSCMO_AGENT_SECRET="$escaped_agent_secret"
ASSCMO_INVENTORY_TOKEN=""

ASSCMO_AGENT_NAME="$DEFAULT_AGENT_NAME"
ASSCMO_AGENT_CHANNEL="$DEFAULT_AGENT_CHANNEL"
EOF

    install -d -m 700 /etc/ass-cmo
    mv "$config_tmp" "$CONFIG_FILE"
    chmod 600 "$CONFIG_FILE"
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --base-url)
            shift
            if [ "$#" -eq 0 ]; then
                usage
                exit 2
            fi
            BASE_URL="$1"
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            log_error "unknown argument: $1"
            usage
            exit 2
            ;;
    esac
    shift
done

if [ "$(id -u)" -ne 0 ]; then
    log_error "this installer must be run as root"
    exit 1
fi

if [ -z "$BASE_URL" ]; then
    log_error "--base-url is required"
    usage
    exit 2
fi

BASE_URL="${BASE_URL%/}"

log_section "Installing ASS-CMO Linux agent"
log_info "Base URL: $BASE_URL"
log_info "Config file: $CONFIG_FILE"

need_cmd curl
need_cmd jq

tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

download() {
    url="$1"
    dst="$2"
    log_info "Downloading $url"
    curl -fsSL --retry 3 --retry-delay 2 --connect-timeout 10 --max-time 120 "$url" -o "$dst"
    if [ ! -s "$dst" ]; then
        log_error "downloaded file is missing or empty: $url"
        exit 1
    fi
}

log_step "Downloading agent files"
download "$BASE_URL/agents/linux/ass-cmo-agent" "$tmpdir/ass-cmo-agent"
download "$BASE_URL/agents/linux/VERSION" "$tmpdir/VERSION"
download "$BASE_URL/agents/linux/ass-cmo-agent.service" "$tmpdir/ass-cmo-agent.service"
download "$BASE_URL/agents/linux/ass-cmo-agent.timer" "$tmpdir/ass-cmo-agent.timer"

test -s "$tmpdir/ass-cmo-agent"
test -s "$tmpdir/VERSION"
test -s "$tmpdir/ass-cmo-agent.service"
test -s "$tmpdir/ass-cmo-agent.timer"

install -d -m 700 /etc/ass-cmo
agent_version="$(tr -d '\r\n' < "$tmpdir/VERSION" 2>/dev/null || true)"
agent_version="${agent_version:-unknown-install}"

if [ -f "$CONFIG_FILE" ]; then
    chmod 600 "$CONFIG_FILE"
    log_info "Keeping existing config: $CONFIG_FILE"
else
    start_body="$tmpdir/enroll-start.json"
    start_response="$tmpdir/enroll-start-response.json"
    poll_response="$tmpdir/enroll-poll-response.json"
    config_tmp="$tmpdir/agent.conf"

    uid="$(enroll_uid)"
    hostname_value="$(enroll_hostname)"
    fqdn_value="$(enroll_fqdn)"

    jq -n \
        --arg uid "$uid" \
        --arg hostname "$hostname_value" \
        --arg fqdn "$fqdn_value" \
        --arg agent_version "$agent_version" \
        '{uid: $uid, hostname: $hostname, fqdn: $fqdn, os_type: "linux", agent_version: $agent_version}' > "$start_body"

    log_step "Requesting enrollment"
    start_code="$(curl -sS -o "$start_response" -w '%{http_code}' --connect-timeout 10 --max-time 30 -H 'Content-Type: application/json' --data-binary "@$start_body" "$BASE_URL/enroll.php")"
    if [ "$start_code" != "200" ]; then
        log_error "enrollment start failed (HTTP $start_code)"
        exit 1
    fi

    request_id="$(json_get_int request_id "$start_response")"
    poll_token="$(json_get_string poll_token "$start_response")"
    pairing_code="$(json_get_string pairing_code "$start_response")"
    poll_interval="$(json_int_or_default poll_interval "$start_response" "$DEFAULT_POLL_INTERVAL")"
    enroll_timeout="$(json_int_or_default expires_in "$start_response" "$DEFAULT_ENROLL_TIMEOUT")"

    if [ -z "$request_id" ] || [ -z "$poll_token" ] || [ -z "$pairing_code" ]; then
        log_error "enrollment start response is missing required fields"
        exit 1
    fi

    verification_url="$(json_get_string verification_url "$start_response")"

    poll_curl_config="$tmpdir/enroll-poll.curl"
    umask 077
    printf 'header = "X-Poll-Token: %s"\n' "$poll_token" > "$poll_curl_config"

    printf '\n'
    log_step "Enrollment approval required"
    log_info "Pairing code: ${TTY_BOLD}${pairing_code}${TTY_RESET}"
    if [ -n "$verification_url" ]; then
        log_info "Approve this enrollment at:"
        printf '     %s\n' "${TTY_CYAN}${verification_url}${TTY_RESET}"
    else
        log_info "Approve this pending enrollment in the ASS-CMO admin UI for:"
        printf '     %s\n' "${TTY_CYAN}${BASE_URL}${TTY_RESET}"
    fi
    printf '\n'

    log_wait "Waiting for approval (checking every ${poll_interval}s, timeout ${enroll_timeout}s)..."

    start_ts="$(date +%s)"

    while :; do
        poll_code="$(curl -sS -G -o "$poll_response" -w '%{http_code}' --connect-timeout 10 --max-time 30 \
            --config "$poll_curl_config" \
            --data-urlencode "action=poll" \
            --data-urlencode "request_id=$request_id" \
            "$BASE_URL/enroll.php")"

        case "$poll_code" in
            200)
                status="$(json_get_string status "$poll_response")"
                case "$status" in
                    pending)
                        now_ts="$(date +%s)"
                        if [ $((now_ts - start_ts)) -ge "$enroll_timeout" ]; then
                            log_error "enrollment approval timed out"
                            exit 1
                        fi
                        sleep "$poll_interval"
                        ;;
                    denied)
                        log_error "enrollment request was denied"
                        exit 1
                        ;;
                    approved)
                        agent_secret="$(json_get_string agent_secret "$poll_response")"
                        if [ -z "$agent_secret" ]; then
                            log_error "approved enrollment response did not include agent_secret"
                            exit 1
                        fi
                        write_agent_config "$agent_secret" "$config_tmp"
                        log_ok "Enrollment approved; local agent config created: $CONFIG_FILE"
                        break
                        ;;
                    *)
                        log_error "unexpected enrollment poll status"
                        exit 1
                        ;;
                esac
                ;;
            404)
                log_error "enrollment request expired or was not found"
                exit 1
                ;;
            *)
                log_error "enrollment poll failed (HTTP $poll_code)"
                exit 1
                ;;
        esac
    done
fi

log_step "Installing agent and systemd units"
install -d -m 755 /usr/share/ass-cmo-agent
install -m 644 "$tmpdir/VERSION" /usr/share/ass-cmo-agent/VERSION
install -m 755 "$tmpdir/ass-cmo-agent" /usr/local/sbin/ass-cmo-agent
install -m 644 "$tmpdir/ass-cmo-agent.service" /etc/systemd/system/ass-cmo-agent.service
install -m 644 "$tmpdir/ass-cmo-agent.timer" /etc/systemd/system/ass-cmo-agent.timer

if [ -f /etc/arch-release ]; then
    download "$BASE_URL/agents/linux/ass-cmo-agent.hook" "$tmpdir/ass-cmo-agent.hook"
    install -d -m 755 /etc/pacman.d/hooks
    install -m 644 "$tmpdir/ass-cmo-agent.hook" /etc/pacman.d/hooks/ass-cmo-agent.hook
fi

if [ -f /etc/debian_version ]; then
    download "$BASE_URL/agents/linux/99ass-cmo-agent" "$tmpdir/99ass-cmo-agent"
    install -d -m 755 /etc/apt/apt.conf.d
    install -m 644 "$tmpdir/99ass-cmo-agent" /etc/apt/apt.conf.d/99ass-cmo-agent
fi

log_step "Configuring systemd timer"
systemctl daemon-reload
systemctl enable --now ass-cmo-agent.timer

log_step "Running first inventory submission"
/usr/local/sbin/ass-cmo-agent

log_ok "ASS-CMO Linux agent installed"
