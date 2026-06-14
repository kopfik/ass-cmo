#!/bin/sh
set -eu

die() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

info() {
  printf 'OK: %s\n' "$*"
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "$1 is required"
}

has_non_space() {
  case "$1" in
    *[![:space:]]*) return 0 ;;
    *) return 1 ;;
  esac
}

json_get() {
  file="$1"
  key="$2"

  php -r '$file = $argv[1]; $key = $argv[2]; $data = json_decode((string)file_get_contents($file), true); if (!is_array($data) || !array_key_exists($key, $data) || $data[$key] === null) { exit(1); } $value = $data[$key]; if (is_bool($value)) { echo $value ? "true" : "false"; exit(0); } if (is_scalar($value)) { echo (string)$value; exit(0); } exit(1);' "$file" "$key"
}

json_has_non_empty_string() {
  file="$1"
  key="$2"

  php -r '$file = $argv[1]; $key = $argv[2]; $data = json_decode((string)file_get_contents($file), true); if (!is_array($data) || !array_key_exists($key, $data) || !is_string($data[$key]) || $data[$key] === "") { exit(1); }' "$file" "$key"
}

expect_http() {
  label="$1"
  actual="$2"
  expected="$3"
  body_file="$4"

  if [ "$actual" != "$expected" ]; then
    printf 'ERROR: %s returned HTTP %s, expected %s\n' "$label" "$actual" "$expected" >&2
    printf 'Response body:\n' >&2
    sed 's/"agent_secret"[[:space:]]*:[[:space:]]*"[^"]*"/"agent_secret":"<redacted>"/g' "$body_file" >&2
    printf '\n' >&2
    exit 1
  fi
}

expect_json_value() {
  label="$1"
  body_file="$2"
  key="$3"
  expected="$4"

  actual="$(json_get "$body_file" "$key" 2>/dev/null || true)"
  if [ "$actual" != "$expected" ]; then
    die "$label expected JSON $key=$expected"
  fi
}

BASE_URL="${BASE_URL:-${ASSCMO_BASE_URL:-}}"
APPROVE_TOKEN="${ASSCMO_ENROLLMENT_APPROVE_TOKEN:-}"

has_non_space "$BASE_URL" || die "set BASE_URL or ASSCMO_BASE_URL"
has_non_space "$APPROVE_TOKEN" || die "set ASSCMO_ENROLLMENT_APPROVE_TOKEN for the approve step"

need_cmd curl
need_cmd php

BASE_URL="${BASE_URL%/}"
uid="dev-smoke-$(date +%s)-$$"
hostname="ass-cmo-enroll-smoke-$$"
fqdn="${hostname}.local"

tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

start_body="$tmpdir/start.json"
start_response="$tmpdir/start-response.json"
pending_response="$tmpdir/pending-response.json"
approve_body="$tmpdir/approve.json"
approve_response="$tmpdir/approve-response.json"
approved_response="$tmpdir/approved-response.json"
inventory_body="$tmpdir/inventory.json"
inventory_response="$tmpdir/inventory-response.txt"
delivered_response="$tmpdir/delivered-response.json"

printf '{"uid":"%s","hostname":"%s","fqdn":"%s","os_type":"linux","agent_version":"dev-smoke"}\n' "$uid" "$hostname" "$fqdn" > "$start_body"

start_code="$(curl -sS -o "$start_response" -w '%{http_code}' --connect-timeout 10 --max-time 30 -H 'Content-Type: application/json' --data-binary "@$start_body" "$BASE_URL/enroll.php")"
expect_http "start enrollment" "$start_code" "200" "$start_response"

request_id="$(json_get "$start_response" request_id 2>/dev/null || true)"
poll_token="$(json_get "$start_response" poll_token 2>/dev/null || true)"
pairing_code="$(json_get "$start_response" pairing_code 2>/dev/null || true)"

has_non_space "$request_id" || die "start response did not contain request_id"
has_non_space "$poll_token" || die "start response did not contain poll_token"
has_non_space "$pairing_code" || die "start response did not contain pairing_code"
info "start enrollment accepted for request_id=$request_id"

poll_url="$BASE_URL/enroll.php?action=poll&request_id=$request_id"

pending_code="$(curl -sS -o "$pending_response" -w '%{http_code}' --connect-timeout 10 --max-time 30 -H "X-Poll-Token: $poll_token" "$poll_url")"
expect_http "initial poll" "$pending_code" "200" "$pending_response"
expect_json_value "initial poll" "$pending_response" status pending
info "initial poll returned pending"

printf '{"request_id":%s}\n' "$request_id" > "$approve_body"

approve_code="$(curl -sS -o "$approve_response" -w '%{http_code}' --connect-timeout 10 --max-time 30 -H 'Content-Type: application/json' -H "Authorization: Bearer $APPROVE_TOKEN" --data-binary "@$approve_body" "$BASE_URL/enroll.php?action=approve")"
expect_http "approve enrollment" "$approve_code" "200" "$approve_response"
expect_json_value "approve enrollment" "$approve_response" status approved
info "approve endpoint accepted request_id=$request_id"

approved_code="$(curl -sS -o "$approved_response" -w '%{http_code}' --connect-timeout 10 --max-time 30 -H "X-Poll-Token: $poll_token" "$poll_url")"
expect_http "approved poll" "$approved_code" "200" "$approved_response"
expect_json_value "approved poll" "$approved_response" status approved
agent_secret="$(json_get "$approved_response" agent_secret 2>/dev/null || true)"
has_non_space "$agent_secret" || die "approved poll did not include agent_secret"
info "approved poll delivered agent_secret"

printf '{"uid":"%s","hostname":"%s","fqdn":"%s","os_type":"linux","agent_version":"dev-smoke"}\n' "$uid" "$hostname" "$fqdn" > "$inventory_body"

inventory_code="$(curl -sS -o "$inventory_response" -w '%{http_code}' --connect-timeout 10 --max-time 30 -H 'Content-Type: application/json' -H "X-Agent-Secret: $agent_secret" --data-binary "@$inventory_body" "$BASE_URL/inventory.php")"
expect_http "inventory ingest with agent_secret" "$inventory_code" "200" "$inventory_response"
info "inventory ingest accepted per-host agent_secret"

delivered_code="$(curl -sS -o "$delivered_response" -w '%{http_code}' --connect-timeout 10 --max-time 30 "$poll_url")"
if [ "$delivered_code" = "200" ]; then
  expect_json_value "second approved poll" "$delivered_response" status approved
  expect_json_value "second approved poll" "$delivered_response" secret_delivered true
  if json_has_non_empty_string "$delivered_response" agent_secret; then
    die "second approved poll unexpectedly returned agent_secret"
  fi
  info "second approved poll confirmed secret_delivered=true without returning agent_secret"
elif [ "$delivered_code" = "404" ]; then
  info "second approved poll returned 404 after one-time secret delivery"
else
  expect_http "second approved poll" "$delivered_code" "200 or 404" "$delivered_response"
fi
info "enrollment dev smoke test passed"
