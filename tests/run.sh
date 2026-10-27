#!/usr/bin/env bash
# Runs harness scenarios against the committed code (avant) or the working tree (apres).
# Usage: tests/run.sh avant|apres [scenario-name|script-path ...]
# Prints one line per check: OK, ECHEC or N/A. Exit 1 when any check prints ECHEC.
set -uo pipefail

here=$(cd "$(dirname "$0")" && pwd)
repo=$(dirname "$here")
home=${R2T_HOME:-$HOME/.cache/r2-offload-tests}
port=${R2T_PORT:-9787}
wp_dir=$home/wp
wp_bin=$(type -P wp)
wp() { php -d memory_limit=1G "$wp_bin" "$@"; }
variant=${1:?avant|apres}
shift

case $variant in
    avant)
        src=$home/head
        rm -rf "$src" && mkdir -p "$src"
        git -C "$repo" archive HEAD | tar -x -C "$src"
        ;;
    apres) src=$repo ;;
    *) echo "variant inconnu : $variant" >&2; exit 2 ;;
esac
ln -sfn "$src" "$wp_dir/wp-content/plugins/numos-r2-offload"

export R2T_STATE=$home/state R2T_ENDPOINT=http://127.0.0.1:$port
export R2T_WP="php -d memory_limit=1G $wp_bin --path=$wp_dir"
PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$port" "$here/fake-s3.php" > "$home/fake-s3.log" 2>&1 &
server=$!
trap 'kill $server 2>/dev/null' EXIT
for _ in $(seq 50); do
    curl -s -o /dev/null -I "$R2T_ENDPOINT/test-bucket" && break
    sleep 0.1
done

if [ $# -gt 0 ]; then
    scenarios=()
    for name in "$@"; do
        if [ -f "$name" ]; then scenarios+=("$name"); else scenarios+=("$here/scenarios/$name.php"); fi
    done
else
    scenarios=("$here"/scenarios/*.php)
fi

failed=0
for scenario in "${scenarios[@]}"; do
    name=$(basename "$scenario" .php)
    out=$(wp --path="$wp_dir" eval-file "$scenario" 2>&1)
    status=$?
    report=$(printf '%s\n' "$out" | grep -E '^(OK|ECHEC|N/A) ')
    fatal=$(printf '%s\n' "$out" | grep -m1 -E 'Fatal error|Uncaught|^Error: ')
    if [ -n "$fatal" ]; then
        report+=$'\n'"ECHEC $name : $fatal"
    elif [ "$status" -ne 0 ]; then
        report+=$'\n'"ECHEC $name : code de sortie $status"
    elif [ -z "$report" ]; then
        report="ECHEC $name : aucune sortie"
    fi
    printf '%s\n' "$report" | sed '/^$/d'
    grep -q '^ECHEC ' <<< "$report" && failed=1
done
exit $failed
