#!/bin/bash

#the installation updated to what is pushed (run it in the installation that is deployed to, e.g. /home/app): the repository with its submodules, the MultiTenant
#plugin's own repository when it has it, tc-lib-pdf when it is missing (scripts/tc-lib-pdf.sh), the new indexes, the data migrations (sys::migrate), then PHP-FPM and
#the daemon restarted.
#CLD: 2026-11-24T12:00:00+02:00: run asks about the plugins RegionalRO and RegionalEU (their own public repositories, PLUGIN_REPOS): cloned ones updated with the app
#(asked, on by default), missing ones cloned into app/plugins/ and turned on in plugins.json (asked, off by default); without a terminal the defaults, nothing asked
#The repositories are fetched first and only fast-forwarded, so a remote that cannot be reached or a local commit stops it before anything changes.
#Every step can be run again: after a failure, fix it and run it again.
# new
#As root (PHP-FPM can only be restarted by root; the git and the indexes steps run as the app's user):
#   sudo ./deploy.sh run
#   ./deploy.sh status       the commits here and on the remote; changes nothing here

set -euo pipefail

SELF_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
BASE=$SELF_DIR
APP_USER=$(stat -c %U "$BASE/app")
PLUGIN="$BASE/app/plugins/MultiTenant"
DAEMON=nfn-daemon-$APP_USER
#the plugins in their own (public) repositories: <name> <repository> <what it has>, in app/plugins/<name>
PLUGIN_REPOS=(
    "RegionalRO https://github.com/serbanconstantinescu/qflows-regional-ro.git Romania: ANAF, BNR, e-Factura, couriers"
    "RegionalEU https://github.com/serbanconstantinescu/qflows-regional-eu.git the EU: VIES, ECB rates, TED tenders"
)
#the plugins of PLUGIN_REPOS updated with the app (cloned ones) and the ones cloned (missing ones): ask_plugins, or every cloned one for status
PLUGINS_UPDATE=()
PLUGINS_ADD=()

usage() {
    echo "usage: sudo $0 {run | status}"
    exit "${1:-1}"
}

#as the app's user, whoever runs this
as_user() {
    if [ "$(id -un)" = "$APP_USER" ]; then
        "$@"
    elif [ "$(id -u)" -eq 0 ]; then
        sudo -H -u "$APP_USER" "$@"
    else
        echo "run it as root or as $APP_USER"
        exit 1
    fi
}

#ask_yes <var> <question> <y|n>: 1 or 0 in <var>; without a terminal the default
ask_yes() {
    local answer
    if [ ! -t 0 ]; then
        answer=$3
    else
        while true; do
            read -r -p "$2 [$([ "$3" = y ] && echo Y/n || echo y/N)]: " answer
            answer=${answer:-$3}
            case ${answer,,} in y|yes|n|no) break ;; esac
        done
    fi
    case ${answer,,} in
        y|yes) printf -v "$1" '%s' 1 ;;
        *) printf -v "$1" '%s' 0 ;;
    esac
}

#the plugins of PLUGIN_REPOS asked: a cloned one updated (PLUGINS_UPDATE), a missing one cloned (PLUGINS_ADD)
ask_plugins() {
    local entry name url what yes
    [ -t 0 ] || echo "no terminal: the cloned plugins are updated, the missing ones not cloned"
    for entry in "${PLUGIN_REPOS[@]}"; do
        read -r name url what <<< "$entry"
        if [ -e "$BASE/app/plugins/$name/.git" ]; then
            ask_yes yes "Update the plugin $name ($what)" y
            [ "$yes" -eq 0 ] || PLUGINS_UPDATE+=("$name")
        elif [ ! -e "$BASE/app/plugins/$name" ]; then
            ask_yes yes "Add the plugin $name ($what)" n
            [ "$yes" -eq 0 ] || PLUGINS_ADD+=("$name")
        else
            echo "$BASE/app/plugins/$name is not a git repository: left as it is"
        fi
    done
}

#the missing plugins asked (PLUGINS_ADD) cloned as the app's user, then turned on in plugins.json when it does not name them (an entry set to "enabled": false stays off)
add_plugins() {
    local entry name url what
    [ "${#PLUGINS_ADD[@]}" -gt 0 ] || return 0
    for entry in "${PLUGIN_REPOS[@]}"; do
        read -r name url what <<< "$entry"
        [[ " ${PLUGINS_ADD[*]} " == *" $name "* ]] || continue
        echo "== the plugin $name: $url"
        as_user git clone -q "$url" "$BASE/app/plugins/$name"
        echo "   now: $(show_commit "$BASE/app/plugins/$name" HEAD)"
    done
    as_user /usr/bin/php -r '$f = $argv[1] . "/plugins.json";
        $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
        if (!is_array($j)) { fwrite(STDERR, "$f is not JSON: the plugins were not turned on, add them there\n"); exit(1); }
        $added = [];
        foreach (array_slice($argv, 2) as $p) {
            if (!isset($j[$p]) && is_file($argv[1] . "/app/plugins/$p/Plugin.php")) { $j[$p] = [ "enabled" => true ]; $added[] = $p; }
        }
        if ($added) { file_put_contents("$f.tmp", json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"); rename("$f.tmp", $f); echo "   plugins.json: " . implode(", ", $added) . " turned on\n"; }' \
        "$BASE" "${PLUGINS_ADD[@]}"
}

#the repositories of this installation: the app, MultiTenant when it has its own, the plugins of PLUGIN_REPOS to update
repos() {
    local name
    echo "$BASE"
    if [ -e "$PLUGIN/.git" ]; then
        echo "$PLUGIN"
    fi
    for name in "${PLUGINS_UPDATE[@]}"; do
        echo "$BASE/app/plugins/$name"
    done
}

#the PHP-FPM service that is running (its name depends on how PHP was installed)
fpm_unit() {
    systemctl list-units --type=service --state=running --no-legend --plain '*php*fpm*' | awk 'NR==1 { print $1 }'
}

#the app's version in this installation (app/data/Version.php)
version() {
    sed -n "s/.*const NUMBER = '\(.*\)';.*/\1/p" "$BASE/app/data/Version.php" 2>/dev/null || true
}

show_commit() {
    as_user git -C "$1" log -1 --format='%h %s' "$2" | cut -c1-100
}

#the commits of repository $1 that are here and not on the remote, newest first; the first 20, then how many more.
#A rewritten history on the remote gives every commit another hash: those found on the remote by author date and subject are counted, not listed.
#The output of git is captured first: piped straight from sudo the list came out empty, and the count did not
local_commits() {
    local here remote real total own
    here=$(as_user git -C "$1" log --format=$'%at %s\t%h %ad %s' --date=short '@{u}..HEAD')
    remote=$(as_user git -C "$1" log --format='%at %s' 'HEAD..@{u}')
    if [ -z "$here" ]; then
        echo "  only here: 0 commit(s)"
        return 0
    fi
    real=$(awk -F'\t' 'NR==FNR { seen[$0]; next } !($1 in seen) { print $2 }' <(printf '%s\n' "$remote") <(printf '%s\n' "$here"))
    total=$(printf '%s\n' "$here" | wc -l)
    own=0
    if [ -n "$real" ]; then
        own=$(printf '%s\n' "$real" | wc -l)
    fi
    echo "  only here: $total commit(s) by hash, $own of them not on the remote by date and subject"
    if [ "$own" -gt 0 ]; then
        printf '%s\n' "$real" | awk 'NR <= 20 { print "    " $0 }' | cut -c1-110
        if [ "$own" -gt 20 ]; then
            echo "    ... and $((own - 20)) more"
        fi
    fi
    if [ "$own" -lt "$total" ]; then
        echo "  the other $((total - own)) are on the remote under other hashes: its history was rewritten"
    fi
    if [ "$own" -eq 0 ]; then
        echo "  no work of this clone would be lost. To take the remote's history, as $APP_USER, after checking git status (uncommitted changes are discarded too):"
        echo "    git -C $1 reset --hard '@{u}'"
    fi
}

status() {
    local repo entry
    for entry in "${PLUGIN_REPOS[@]}"; do
        [ ! -e "$BASE/app/plugins/${entry%% *}/.git" ] || PLUGINS_UPDATE+=("${entry%% *}")
    done
    while read -r repo; do
        as_user git -C "$repo" fetch -q
        echo "$repo"
        echo "  here:   $(show_commit "$repo" HEAD)"
        echo "  remote: $(show_commit "$repo" '@{u}')"
        local_commits "$repo"
    done < <(repos)
    echo "version: $(version)"
    echo "$DAEMON: $(systemctl is-active "$DAEMON" || true)"
}

run() {
    local repo fpm
    [ "$(id -u)" -eq 0 ] || { echo "run it as root: sudo $0 run"; exit 1; }
    ask_plugins

    #fetched first: both or nothing when a remote cannot be reached
    while read -r repo; do
        as_user git -C "$repo" fetch -q
        if ! as_user git -C "$repo" merge-base --is-ancestor HEAD '@{u}'; then
            echo "$repo has commits the remote does not have: nothing changed, solve it first"
            local_commits "$repo"
            exit 1
        fi
        #CLD: 2026-11-23T14:00:00Z: files changed here that the update changes too (the fast-forward would stop half-way): nothing changed, listed. Settings belong in
        #app.env (e.g. the daemon's: DAEMON_<OPTION>), which git does not track
        local clash
        clash=$(comm -12 <(as_user git -C "$repo" diff --name-only HEAD '@{u}' | sort) <(as_user git -C "$repo" diff --name-only HEAD | sort))
        if [ -n "$clash" ]; then
            echo "$repo: files changed here that the update changes too: nothing changed. Commit them, or undo them (git -C $repo checkout -- <file>);"
            echo "a setting belongs in app.env (the daemon's: DAEMON_<OPTION>, see app.env.example)"
            printf '%s\n' "$clash" | sed 's/^/  /'
            exit 1
        fi
    done < <(repos)

    while read -r repo; do
        echo "== $repo: $(show_commit "$repo" HEAD)"
        as_user git -C "$repo" merge --ff-only -q '@{u}'
        echo "   now: $(show_commit "$repo" HEAD)"
    done < <(repos)
    as_user git -C "$BASE" submodule update -q --init --recursive
    add_plugins

    #CLD: 2026-11-22T15:00:00+02:00: tc-lib-pdf (the PDF document step; not in git) when it is missing; there already: kept (scripts/tc-lib-pdf.sh install updates it)
    if [ ! -f "$BASE/app/pkgs/tc-lib-pdf/autoload.php" ]; then
        echo "== tc-lib-pdf"
        as_user "$BASE/scripts/tc-lib-pdf.sh" install || echo "   not installed (above): the PDF document step fails until $BASE/scripts/tc-lib-pdf.sh install runs as $APP_USER"
    fi

    echo "== indexes"
    (cd "$BASE/app/commands" && as_user ../v sys::indexes)

    # the data migrations of the versions pulled (util\Migrations), before the restart: the new code expects the migrated data
    echo "== migrations"
    (cd "$BASE/app/commands" && as_user ../v sys::migrate)

    fpm=$(fpm_unit)
    if [ -z "$fpm" ]; then
        echo "no PHP-FPM service is running: start it"
        exit 1
    fi
    echo "== restart: $fpm $DAEMON"
    systemctl restart "$fpm" "$DAEMON"
    systemctl is-active --quiet "$fpm" "$DAEMON" || { echo "not running after the restart: systemctl status $fpm $DAEMON"; exit 1; }
    echo "Done: version $(version)"
}

main() {
    case "${1:-}" in
        run) run ;;
        status) status ;;
        -h|--help) usage 0 ;;
        *) usage 1 ;;
    esac
}

#last line: git may replace this file while it runs
main "$@"
