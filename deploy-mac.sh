#!/usr/bin/env bash
# Deploy index.php and quiz.php from a Mac.
# Usage: ./deploy-mac.sh /path/to/key.pem
# Does not upload quizzes/, quiz_mapping.csv, quiz_data.json, or quiz_results.json.
set -euo pipefail

HOST="ubuntu@skylab.fusion.co.th"
DEST="/var/www/html/hr"
FILES=(index.php quiz.php)

if [[ $# -ne 1 || -z "${1:-}" ]]; then
  echo "Usage: $0 /path/to/key.pem" >&2
  exit 1
fi

PEM="$1"
if [[ ! -f "$PEM" ]]; then
  echo "PEM file not found: $PEM" >&2
  exit 1
fi

chmod 600 "$PEM"

ROOT="$(cd "$(dirname "$0")" && pwd)"
SSH=(ssh -i "$PEM" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new)
RSH="$(printf '%q ' "${SSH[@]}")"

paths=()
for file in "${FILES[@]}"; do
  if [[ ! -f "$ROOT/$file" ]]; then
    echo "Missing local file: $ROOT/$file" >&2
    exit 1
  fi
  paths+=("$ROOT/$file")
done

echo "Uploading ${FILES[*]} to $HOST:$DEST/"
rsync -avz -e "$RSH" "${paths[@]}" "$HOST:$DEST/"

echo "Checking PHP syntax on the server"
remote_checks=""
for file in "${FILES[@]}"; do
  remote_checks+="php -l $DEST/$file && "
done
remote_checks+="echo OK"
"${SSH[@]}" "$HOST" "$remote_checks"
