#!/bin/sh
# Write EDM test credentials to a local, git-unreachable, mode-600 file.
#
# Why this design:
#  - The file lives OUTSIDE the git work tree, so `git add` cannot reach it.
#  - Values are read with `read -r` while terminal echo is disabled, so they do
#    not appear on screen and do not enter shell history.
#  - Values are never passed as command-line arguments (argv is world-readable
#    via `ps` on many systems).
#  - The file is bind-mounted read-only into the container instead of being
#    exported as container environment variables, because `-e` values remain
#    readable through `docker inspect` while the container object exists.
#
# Usage:  ./scripts/edm-test-credentials.sh          # create or replace
#         ./scripts/edm-test-credentials.sh --status # show presence only
set -eu

cred_dir="${XDG_CONFIG_HOME:-$HOME/.config}/kuka-island"
cred_file="$cred_dir/edm-test.env"

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
case "$cred_file" in
  "$project_dir"/*)
    echo "REFUSING: credential path resolves inside the repository ($cred_file)." >&2
    exit 1
    ;;
esac

show_status() {
  if [ ! -f "$cred_file" ]; then
    echo "EDM_TEST_CREDENTIALS=ABSENT|path_outside_repo:yes"
    return 0
  fi
  mode=$(stat -f '%Lp' "$cred_file" 2>/dev/null || stat -c '%a' "$cred_file" 2>/dev/null || echo "unknown")
  echo "EDM_TEST_CREDENTIALS=PRESENT|mode:$mode|path_outside_repo:yes|git_reachable:no"
  for key in KUKA_EDM_USERNAME KUKA_EDM_PASSWORD KUKA_EDM_SECRET_KEY KUKA_EDM_SENDER_VKN KUKA_EDM_SENDER_ALIAS KUKA_EDM_SERIES_EARCHIVE KUKA_EDM_SERIES_EINVOICE; do
    if grep -qE "^${key}=.+$" "$cred_file" 2>/dev/null; then
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

read_secret() {
  # $1 = prompt, $2 = variable name to assign
  printf '%s' "$1" >&2
  stty_saved=$(stty -g 2>/dev/null || echo "")
  [ -n "$stty_saved" ] && stty -echo
  IFS= read -r _secret_value || true
  [ -n "$stty_saved" ] && stty "$stty_saved"
  printf '\n' >&2
  eval "$2=\$_secret_value"
  unset _secret_value
}

echo "EDM test credentials will be written to:" >&2
echo "  $cred_file  (mode 600, outside the git work tree)" >&2
echo "Nothing you type is echoed, logged, or passed as a command argument." >&2
echo "Leave a field empty to omit it." >&2
echo "" >&2

read_secret "EDM test username        : " edm_user
read_secret "EDM test password        : " edm_pass
read_secret "EDM SECRET_KEY (optional): " edm_secret
read_secret "Sender VKN (optional, enables CheckUser): " edm_vkn
read_secret "Sender alias (optional)  : " edm_alias
read_secret "e-Archive series, e.g. KUK (optional): " edm_series_earchive
read_secret "e-Invoice series, e.g. KUK (optional): " edm_series_einvoice

if [ -z "$edm_user" ] || [ -z "$edm_pass" ]; then
  echo "REFUSING: username and password are both required." >&2
  exit 1
fi

umask 077
mkdir -p "$cred_dir"
tmp_file="$cred_file.tmp.$$"
: > "$tmp_file"
chmod 600 "$tmp_file"

{
  echo "# EDM test credentials for Kuka Island local verification."
  echo "# Mode 600. Outside the git work tree. Do not copy into the repository."
  echo "KUKA_EDM_USERNAME=$edm_user"
  echo "KUKA_EDM_PASSWORD=$edm_pass"
  [ -n "$edm_secret" ]            && echo "KUKA_EDM_SECRET_KEY=$edm_secret"
  [ -n "$edm_vkn" ]               && echo "KUKA_EDM_SENDER_VKN=$edm_vkn"
  [ -n "$edm_alias" ]             && echo "KUKA_EDM_SENDER_ALIAS=$edm_alias"
  [ -n "$edm_series_earchive" ]   && echo "KUKA_EDM_SERIES_EARCHIVE=$edm_series_earchive"
  [ -n "$edm_series_einvoice" ]   && echo "KUKA_EDM_SERIES_EINVOICE=$edm_series_einvoice"
  true
} > "$tmp_file"

mv "$tmp_file" "$cred_file"
chmod 600 "$cred_file"

unset edm_user edm_pass edm_secret edm_vkn edm_alias edm_series_earchive edm_series_einvoice

echo "" >&2
show_status
