#!/bin/sh
# Write DHL sandbox credentials to a local, git-unreachable, mode-600 file.
#
# Design guarantees:
#  - The file lives OUTSIDE the git work tree, so `git add` cannot reach it.
#  - Values are read with terminal echo disabled, so they never appear on screen
#    and never enter shell history.
#  - No value is ever passed as a command-line argument (argv is readable via
#    `ps` on many systems).
#  - Values are stored VERBATIM. Nothing is trimmed and nothing is unquoted, so
#    leading/trailing spaces and '=' characters inside a value survive intact.
#  - AN EMPTY ANSWER KEEPS THE EXISTING VALUE. This file may already hold the
#    gateway pair, and a prompt loop that wiped it because somebody pressed
#    Return four times would be a credential-destroying tool.
#  - Terminal echo is restored by a trap on every exit path, including Ctrl-C
#    and SIGTERM.
#  - The file is written to a temporary path and renamed atomically. An
#    interrupted run leaves no partial credential file.
#
# Usage:  ./scripts/dhl-test-credentials.sh          # create or amend
#         ./scripts/dhl-test-credentials.sh --status # presence only
set -eu

cred_dir="${XDG_CONFIG_HOME:-$HOME/.config}/kuka-island"
cred_file="$cred_dir/dhl-sandbox.env"

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
case "$cred_file" in
  "$project_dir"/*)
    echo "REFUSING: credential path resolves inside the repository." >&2
    exit 1
    ;;
esac

# The four keys the loader understands, in write order. The first two identify
# the integration at the API gateway; the last two identify the shipping
# account to the Identity API.
CRED_KEYS='KUKA_DHL_SANDBOX_CLIENT_ID
KUKA_DHL_SANDBOX_CLIENT_SECRET
KUKA_DHL_SANDBOX_CUSTOMER_NUMBER
KUKA_DHL_SANDBOX_PASSWORD'

show_status() {
  if [ ! -f "$cred_file" ]; then
    echo "DHL_TEST_CREDENTIALS=ABSENT|path_outside_repo:yes|git_reachable:no"
    return 0
  fi
  mode=$(stat -f '%Lp' "$cred_file" 2>/dev/null || stat -c '%a' "$cred_file" 2>/dev/null || echo "unknown")
  echo "DHL_TEST_CREDENTIALS=PRESENT|mode:$mode|path_outside_repo:yes|git_reachable:no"
  echo "$CRED_KEYS" | while IFS= read -r key; do
    [ -z "$key" ] && continue
    # A key counts as supplied when a non-empty value follows the first '='.
    if grep -q "^${key}=." "$cred_file" 2>/dev/null; then
      echo "  $key=supplied"
    else
      echo "  $key=absent"
    fi
  done
}

if [ "${1:-}" = "--status" ]; then
  show_status
  exit 0
fi

# --- terminal echo + temp file safety net ----------------------------------
stty_saved=$(stty -g 2>/dev/null || echo "")
tmp_file=""

restore() {
  [ -n "$stty_saved" ] && stty "$stty_saved" 2>/dev/null || true
  if [ -n "$tmp_file" ] && [ -f "$tmp_file" ]; then
    rm -f "$tmp_file"
  fi
}
trap 'restore' EXIT
trap 'restore; printf "\naborted, credential file left unchanged\n" >&2; exit 130' INT TERM HUP

# Read the existing value of one key, verbatim, without printing it.
existing_value() {
  [ -f "$cred_file" ] || return 0
  sed -n "s/^$1=//p" "$cred_file" | head -n 1
}

read_secret() {
  # $1 = prompt, $2 = variable name, $3 = existing value.
  if [ -n "$3" ]; then
    printf '%s [mevcut değer korunur, boş bırakın] : ' "$1" >&2
  else
    printf '%s : ' "$1" >&2
  fi
  [ -n "$stty_saved" ] && stty -echo
  IFS= read -r _v || true
  [ -n "$stty_saved" ] && stty "$stty_saved"
  printf '\n' >&2
  if [ -z "$_v" ]; then
    _v=$3
  fi
  eval "$2=\$_v"
  unset _v
}

cat >&2 <<'INFO'
DHL sandbox credentials will be written to a mode-600 file outside the git work tree.
Nothing you type is echoed, logged, or passed as a command argument.
Values are stored verbatim: spaces and '=' characters are preserved.
An empty answer KEEPS the value that is already stored.

Client Id / Client Secret  : API gateway pair (X-IBM-Client-Id / X-IBM-Client-Secret).
Customer Number / Password : Identity API pair, required for /token. Without
                             BOTH of them no call of any kind is made.

INFO

old_client_id=$(existing_value KUKA_DHL_SANDBOX_CLIENT_ID)
old_client_secret=$(existing_value KUKA_DHL_SANDBOX_CLIENT_SECRET)
old_customer=$(existing_value KUKA_DHL_SANDBOX_CUSTOMER_NUMBER)
old_password=$(existing_value KUKA_DHL_SANDBOX_PASSWORD)

read_secret "X-IBM-Client-Id" v_client_id "$old_client_id"
read_secret "X-IBM-Client-Secret" v_client_secret "$old_client_secret"
read_secret "MNG müşteri numarası (customerNumber)" v_customer "$old_customer"
read_secret "MNG müşteri şifresi (password)" v_password "$old_password"

if [ -z "$v_client_id" ] || [ -z "$v_client_secret" ]; then
  echo "REFUSING: client id and client secret are both required. Nothing written." >&2
  exit 1
fi

umask 077
mkdir -p "$cred_dir"
chmod 700 "$cred_dir" 2>/dev/null || true

tmp_file="$cred_file.tmp.$$"
: > "$tmp_file"
chmod 600 "$tmp_file"

emit() {
  # $1 = key, $2 = value. Written verbatim, omitted when empty.
  [ -z "$2" ] && return 0
  printf '%s=%s\n' "$1" "$2" >> "$tmp_file"
}

emit KUKA_DHL_SANDBOX_CLIENT_ID       "$v_client_id"
emit KUKA_DHL_SANDBOX_CLIENT_SECRET   "$v_client_secret"
emit KUKA_DHL_SANDBOX_CUSTOMER_NUMBER "$v_customer"
emit KUKA_DHL_SANDBOX_PASSWORD        "$v_password"

# Atomic publish. Until this rename the destination is untouched.
mv "$tmp_file" "$cred_file"
tmp_file=""
chmod 600 "$cred_file"

unset v_client_id v_client_secret v_customer v_password \
      old_client_id old_client_secret old_customer old_password

echo "" >&2
show_status
