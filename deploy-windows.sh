#!/usr/bin/env bash
# Deploy index.php and quiz.php from Windows Git Bash.
# Usage: bash deploy-windows.sh C:/path/to/key.pem
#        bash deploy-windows.sh 'C:\path\to\key.pem'
# Does not upload quizzes/, quiz_mapping.csv, quiz_data.json, or quiz_results.json.
# OpenSSH on Windows refuses a PEM that other users can read, so this script
# restricts the key file to the current Windows user.
set -euo pipefail

HOST="ubuntu@skylab.fusion.co.th"
DEST="/var/www/html/hr"
FILES=(index.php quiz.php)

if [[ $# -ne 1 || -z "${1:-}" ]]; then
  echo "Usage: bash deploy-windows.sh C:/path/to/key.pem" >&2
  exit 1
fi

PEM="$1"
if [[ "$PEM" =~ ^[A-Za-z]:[\\/] ]]; then
  drive="$(printf '%s' "${PEM:0:1}" | tr '[:upper:]' '[:lower:]')"
  rest="${PEM:2}"
  rest="${rest//\\//}"
  PEM="/${drive}${rest}"
fi

if [[ ! -f "$PEM" ]]; then
  echo "PEM file not found: $PEM" >&2
  exit 1
fi

if command -v icacls.exe >/dev/null 2>&1 && command -v cygpath >/dev/null 2>&1; then
  win_pem="$(cygpath -w "$PEM")"
  win_user="${USERNAME:-$USER}"
  icacls.exe "$win_pem" /inheritance:r >/dev/null
  icacls.exe "$win_pem" /grant:r "${win_user}:(R)" >/dev/null
else
  chmod 600 "$PEM"
fi

ROOT="$(cd "$(dirname "$0")" && pwd)"
SSH=(ssh -i "$PEM" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new)

paths=()
for file in "${FILES[@]}"; do
  if [[ ! -f "$ROOT/$file" ]]; then
    echo "Missing local file: $ROOT/$file" >&2
    exit 1
  fi
  paths+=("$ROOT/$file")
done

echo "Uploading ${FILES[*]} to $HOST:$DEST/"
if command -v rsync >/dev/null 2>&1; then
  rsh="$(printf '%q ' "${SSH[@]}")"
  rsync -avz -e "$rsh" "${paths[@]}" "$HOST:$DEST/"
else
  scp -i "$PEM" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new \
    "${paths[@]}" "$HOST:$DEST/"
fi

echo "Checking PHP syntax on the server"
remote_checks=""
for file in "${FILES[@]}"; do
  remote_checks+="php -l $DEST/$file && "
done
remote_checks+="echo OK"
"${SSH[@]}" "$HOST" "$remote_checks"
