#!/bin/sh
# Read a docker-compose env file the way docker compose reads it.
#
# WHY THIS EXISTS. `.env` is a compose env file, not a shell script, and the two
# formats disagree the moment a value contains a space:
#
#   KUKA_SMTP_FROM_NAME=Kuka Island
#
# is a perfectly ordinary compose entry and a syntax error for `.` -- POSIX
# sourcing reads `Island` as a command and the script dies with exit 127 before
# a single check runs. Measured exactly that way on 4 September 2026, after the
# SMTP variables were added: `make verify` stopped with
# `.env: line 25: Island: command not found`.
#
# Quoting the value in `.env` would also work, but that is the operator's own
# file and every future edit would have to remember the rule. Parsing it here
# removes the rule.
#
# The rules implemented, matching compose: blank lines and `#` comments are
# skipped; a line must contain `=`; the key is everything before the FIRST `=`
# and must be a plain identifier; the value is everything after it, verbatim,
# with at most one matching pair of surrounding quotes removed. No expansion,
# no word splitting, no command substitution -- so a value can contain spaces,
# `$`, backticks or a semicolon without becoming code.

kuka_load_env_file() {
	kuka_env_file=$1

	[ -r "$kuka_env_file" ] || return 0

	while IFS= read -r kuka_env_line || [ -n "$kuka_env_line" ]; do
		# Strip one trailing CR so a CRLF file behaves.
		kuka_env_line=${kuka_env_line%
}
		kuka_env_line=$(printf '%s' "$kuka_env_line" | tr -d '\r')

		case "$kuka_env_line" in
			''|\#*) continue ;;
			*=*) ;;
			*) continue ;;
		esac

		kuka_env_key=${kuka_env_line%%=*}
		kuka_env_key=${kuka_env_key# }
		kuka_env_key=${kuka_env_key%% }

		# Anything that is not a plain identifier is not a variable assignment.
		case "$kuka_env_key" in
			''|*[!A-Za-z0-9_]*) continue ;;
		esac

		kuka_env_value=${kuka_env_line#*=}

		case "$kuka_env_value" in
			\"*\") kuka_env_value=${kuka_env_value#\"}; kuka_env_value=${kuka_env_value%\"} ;;
			\'*\') kuka_env_value=${kuka_env_value#\'}; kuka_env_value=${kuka_env_value%\'} ;;
		esac

		export "$kuka_env_key=$kuka_env_value"
	done < "$kuka_env_file"

	unset kuka_env_file kuka_env_line kuka_env_key kuka_env_value
}
