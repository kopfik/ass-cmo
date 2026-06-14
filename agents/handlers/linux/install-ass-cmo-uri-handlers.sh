#!/bin/sh
set -eu

BIN_DIR="${HOME}/.local/bin"
APP_DIR="${HOME}/.local/share/applications"

OVERWRITE_HANDLERS="${ASSCMO_OVERWRITE_HANDLERS:-0}"
HANDLER_BASE_URL="${ASSCMO_HANDLER_BASE_URL:-${ASSCMO_BASE_URL:-}}"

SCRIPT_DIR=""
case "${0:-}" in
  */*) SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" 2>/dev/null && pwd -P || true)" ;;
esac

mkdir -p "$BIN_DIR" "$APP_DIR"

existing_installation() {
  for path in \
    "${BIN_DIR}/assssh-handler" \
    "${BIN_DIR}/assrdp-handler" \
    "${BIN_DIR}/assweb-handler" \
    "${APP_DIR}/assssh-handler.desktop" \
    "${APP_DIR}/assrdp-handler.desktop" \
    "${APP_DIR}/assweb-handler.desktop"
  do
    [ -e "$path" ] && printf '%s\n' "$path"
  done
}

install_handler_file() {
  name="$1"
  destination="${BIN_DIR}/${name}"

  if [ -n "$SCRIPT_DIR" ] && [ -r "${SCRIPT_DIR}/bundled/${name}" ]; then
    cp "${SCRIPT_DIR}/bundled/${name}" "$destination"
  else
    if [ -z "$HANDLER_BASE_URL" ]; then
      echo "ASS-CMO handler base URL is not set and bundled handler files are not available." >&2
      echo "When piping from curl, run:" >&2
      echo "curl -fsSL https://your-ass-cmo.example/agents/handlers/linux/install-ass-cmo-uri-handlers.sh | ASSCMO_HANDLER_BASE_URL=https://your-ass-cmo.example sh" >&2
      exit 1
    fi

    curl -fsSL "${HANDLER_BASE_URL%/}/agents/handlers/linux/bundled/${name}" -o "$destination"
  fi

  chmod +x "$destination"
}

write_desktop_file() {
  scheme="$1"
  name="$2"
  display_name="$3"

  cat > "${APP_DIR}/${name}.desktop" <<EOF
[Desktop Entry]
Type=Application
Name=${display_name}
Exec=${BIN_DIR}/${name} %u
StartupNotify=false
MimeType=x-scheme-handler/${scheme};
NoDisplay=true
EOF
}

if [ "$OVERWRITE_HANDLERS" != "1" ]; then
  existing="$(existing_installation || true)"

  if [ -n "$existing" ]; then
    echo "Existing local ASS-CMO URI handler installation found."
    echo "No changes were made."
    echo
    printf '%s\n' "$existing"
    echo
    echo "To keep your local custom handlers, do nothing."
    echo "To replace all local handlers with the bundled version, rerun with:"
    echo "curl -fsSL https://ass-cmo.example/agents/handlers/linux/install-ass-cmo-uri-handlers.sh | ASSCMO_HANDLER_BASE_URL=https://ass-cmo.example ASSCMO_OVERWRITE_HANDLERS=1 sh"
    exit 0
  fi
fi

install_handler_file "assssh-handler"
install_handler_file "assrdp-handler"
install_handler_file "assweb-handler"

write_desktop_file "assssh" "assssh-handler" "ASS-CMO SSH Handler"
write_desktop_file "assrdp" "assrdp-handler" "ASS-CMO RDP Handler"
write_desktop_file "assweb" "assweb-handler" "ASS-CMO Web Handler"

xdg-mime default assssh-handler.desktop x-scheme-handler/assssh >/dev/null 2>&1 || true
xdg-mime default assrdp-handler.desktop x-scheme-handler/assrdp >/dev/null 2>&1 || true
xdg-mime default assweb-handler.desktop x-scheme-handler/assweb >/dev/null 2>&1 || true
update-desktop-database "$APP_DIR" 2>/dev/null || true

echo "ASS-CMO URI handlers installed."
echo "SSH handler: $(xdg-mime query default x-scheme-handler/assssh 2>/dev/null || true)"
echo "RDP handler: $(xdg-mime query default x-scheme-handler/assrdp 2>/dev/null || true)"
echo "WEB handler: $(xdg-mime query default x-scheme-handler/assweb 2>/dev/null || true)"
