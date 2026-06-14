#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)"
TMP_DIR="$(mktemp -d)"
LAUNCH_LOG="$TMP_DIR/launched"
OUTPUT_LOG="$TMP_DIR/output"
export ASSCMO_HANDLER_TEST_LAUNCH_LOG="$LAUNCH_LOG"

cleanup() {
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT INT TERM

make_stub() {
  name="$1"
  cat >"$TMP_DIR/$name" <<'EOF'
#!/bin/sh
printf '%s\n' "$0 $*" >>"${ASSCMO_HANDLER_TEST_LAUNCH_LOG:?}"
exit 90
EOF
  chmod +x "$TMP_DIR/$name"
}

for name in konsole gnome-terminal x-terminal-emulator xterm remmina xfreerdp; do
  make_stub "$name"
done

PATH="$TMP_DIR:$PATH"
export PATH

failures=0

expect_rejected_without_launch() {
  label="$1"
  handler="$2"
  uri="$3"

  rm -f "$LAUNCH_LOG" "$OUTPUT_LOG"

  if "$handler" "$uri" >"$OUTPUT_LOG" 2>&1; then
    printf 'FAIL: %s was accepted\n' "$label" >&2
    failures=$((failures + 1))
    return
  fi

  if [ -s "$LAUNCH_LOG" ]; then
    printf 'FAIL: %s attempted to launch a client\n' "$label" >&2
    cat "$LAUNCH_LOG" >&2
    failures=$((failures + 1))
    return
  fi

  printf 'ok: %s rejected\n' "$label"
}

SSH_HANDLER="$ROOT_DIR/agents/handlers/linux/bundled/assssh-handler"
RDP_HANDLER="$ROOT_DIR/agents/handlers/linux/bundled/assrdp-handler"

expect_rejected_without_launch "SSH option injection target" "$SSH_HANDLER" "assssh://-oProxyCommand=..."
expect_rejected_without_launch "SSH percent-encoded shell metachar target" "$SSH_HANDLER" "assssh://host%20%26%20calc"
expect_rejected_without_launch "SSH percent-encoded newline target" "$SSH_HANDLER" "assssh://host%0Acommand"
expect_rejected_without_launch "RDP whitespace option target" "$RDP_HANDLER" "assrdp://host /admin"

if [ "$failures" -ne 0 ]; then
  exit 1
fi
