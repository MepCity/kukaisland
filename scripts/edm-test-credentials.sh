#!/bin/sh
# Write EDM test credentials to a local, git-unreachable, mode-600 file.
#
# Design guarantees:
#  - The file lives OUTSIDE the git work tree, so `git add` cannot reach it.
#  - Values are read with terminal echo disabled, so they never appear on screen
#    and never enter shell history.
#  - No value is ever passed as a command-line argument (argv is readable via
#    `ps` on many systems).
#  - Values are stored VERBATIM. Nothing is trimmed and nothing is unquoted, so
#    leading/trailing spaces and '=' characters inside a value survive intact.
#  - Terminal echo is restored by a trap on every exit path, including Ctrl-C
#    and SIGTERM.
#  - The file is written to a temporary path and renamed atomically. An
#    interrupted run leaves no partial credential file.
#
# Usage:  ./scripts/edm-test-credentials.sh          # create or replace
#         ./scripts/edm-test-credentials.sh --status # presence only
set -eu

cred_dir="${XDG_CONFIG_HOME:-$HOME/.config}/kuka-island"
cred_file="$cred_dir/edm-test.env"

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
case "$cred_file" in
  "$project_dir"/*)
    echo "REFUSING: credential path resolves inside the repository." >&2
    exit 1
    ;;
esac

# Every key the loader understands, in write order.
CRED_KEYS='KUKA_EDM_USERNAME
KUKA_EDM_PASSWORD
KUKA_EDM_SECRET_KEY
KUKA_EDM_SENDER_VKN
KUKA_EDM_SENDER_ALIAS
KUKA_EDM_SENDER_TITLE
KUKA_EDM_SENDER_TAX_OFFICE
KUKA_EDM_SENDER_ADDRESS
KUKA_EDM_SENDER_DISTRICT
KUKA_EDM_SENDER_CITY
KUKA_EDM_SENDER_POSTCODE
KUKA_EDM_SERIES_EARCHIVE
KUKA_EDM_SERIES_EINVOICE
KUKA_EDM_SANDBOX_RECEIVER_VKN
KUKA_EDM_SANDBOX_PROFILE_ID'

show_status() {
  if [ ! -f "$cred_file" ]; then
    echo "EDM_TEST_CREDENTIALS=ABSENT|path_outside_repo:yes|git_reachable:no"
    return 0
  fi
  mode=$(stat -f '%Lp' "$cred_file" 2>/dev/null || stat -c '%a' "$cred_file" 2>/dev/null || echo "unknown")
  echo "EDM_TEST_CREDENTIALS=PRESENT|mode:$mode|path_outside_repo:yes|git_reachable:no"
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
trap 'restore; printf "\naborted, no credential file written\n" >&2; exit 130' INT TERM HUP

read_secret() {
  # $1 = prompt, $2 = variable name. Value is preserved verbatim.
  printf '%s' "$1" >&2
  [ -n "$stty_saved" ] && stty -echo
  IFS= read -r _v || true
  [ -n "$stty_saved" ] && stty "$stty_saved"
  printf '\n' >&2
  eval "$2=\$_v"
  unset _v
}

cat >&2 <<'INFO'
EDM test credentials will be written to a mode-600 file outside the git work tree.
Nothing you type is echoed, logged, or passed as a command argument.
Values are stored verbatim: spaces and '=' characters are preserved.
Leave a field empty to omit it.

INFO

read_secret "EDM test username                    : " v_username
read_secret "EDM test password                    : " v_password
read_secret "EDM SECRET_KEY (optional)            : " v_secret
read_secret "Sender VKN (enables CheckUser)       : " v_sender_vkn
read_secret "Sender mailbox alias                 : " v_sender_alias
read_secret "Sender company title                 : " v_sender_title
read_secret "Sender tax office                    : " v_sender_tax_office
read_secret "Sender address                       : " v_sender_address
read_secret "Sender district                      : " v_sender_district
read_secret "Sender city                          : " v_sender_city
read_secret "Sender postcode                      : " v_sender_postcode
read_secret "e-Archive series (3 chars, e.g. KUK) : " v_series_earchive
read_secret "e-Invoice series (optional)          : " v_series_einvoice

cat >&2 <<'INFO'

The next two fields are required ONLY for the isolated sandbox invoice
experiment. Do not guess them. Leave them empty unless EDM has confirmed in
writing which receiver identity and which PROFILEID its test account accepts.
The sandbox tool treats an empty value as BLOCKED and never invents one.

INFO

read_secret "Sandbox receiver VKN/TCKN (EDM-confirmed only) : " v_sandbox_receiver
read_secret "Sandbox PROFILEID (EDM-confirmed only)         : " v_sandbox_profile

if [ -z "$v_username" ] || [ -z "$v_password" ]; then
  echo "REFUSING: username and password are both required. Nothing written." >&2
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

{
  printf '%s\n' '# EDM test credentials for Kuka Island local verification.'
  printf '%s\n' '# Mode 600, outside the git work tree. Do not copy into the repository.'
  printf '%s\n' '# Format: KEY=value. The value is everything after the FIRST "=", verbatim.'
  printf '%s\n' '# Do not add quotes: they would be stored as part of the value.'
} >> "$tmp_file"

emit KUKA_EDM_USERNAME             "$v_username"
emit KUKA_EDM_PASSWORD             "$v_password"
emit KUKA_EDM_SECRET_KEY           "$v_secret"
emit KUKA_EDM_SENDER_VKN           "$v_sender_vkn"
emit KUKA_EDM_SENDER_ALIAS         "$v_sender_alias"
emit KUKA_EDM_SENDER_TITLE         "$v_sender_title"
emit KUKA_EDM_SENDER_TAX_OFFICE    "$v_sender_tax_office"
emit KUKA_EDM_SENDER_ADDRESS       "$v_sender_address"
emit KUKA_EDM_SENDER_DISTRICT      "$v_sender_district"
emit KUKA_EDM_SENDER_CITY          "$v_sender_city"
emit KUKA_EDM_SENDER_POSTCODE      "$v_sender_postcode"
emit KUKA_EDM_SERIES_EARCHIVE      "$v_series_earchive"
emit KUKA_EDM_SERIES_EINVOICE      "$v_series_einvoice"
emit KUKA_EDM_SANDBOX_RECEIVER_VKN "$v_sandbox_receiver"
emit KUKA_EDM_SANDBOX_PROFILE_ID   "$v_sandbox_profile"

# Atomic publish. Until this rename the destination is untouched.
mv "$tmp_file" "$cred_file"
tmp_file=""
chmod 600 "$cred_file"

unset v_username v_password v_secret v_sender_vkn v_sender_alias v_sender_title \
      v_sender_tax_office v_sender_address v_sender_district v_sender_city \
      v_sender_postcode v_series_earchive v_series_einvoice \
      v_sandbox_receiver v_sandbox_profile

echo "" >&2
show_status
