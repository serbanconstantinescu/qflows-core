#!/bin/bash

#qflows with Docker Compose: the settings (docker/.env), the images, the containers. Next to install.sh (the servers' way), and as it, it runs from anywhere:
#   mkdir qflows && cd qflows
#   curl -fsSLO https://raw.githubusercontent.com/serbanconstantinescu/qflows-core/main/docker.sh && bash docker.sh install
#CLD: 2026-11-16T23:00:00+02:00: at the repository's root; downloaded alone, it fetches docker/ and scripts/mongo_users.js from GitHub (in a clone: the clone's, never downloaded),
#the app image clones the code (QFLOWS_REPO at QFLOWS_COMMIT: QFLOWS_BRANCH's head when installed or updated); the outgoing mail to mailpit by default;
#the options as name=value, saying what they choose (--mail=mailpit|host-haraka, --browser=yes|no, --code=image|working-tree); docker.sh help lists the commands and options.
#docker compose itself works as well (from docker/: COMPOSE_FILE is in .env). Run as a user of the docker group (or root).
#CLD: 2026-11-17T00:30:00+02:00: --plugins=<list> clones plugins kept in their own (public) repositories into the image (PLUGINS: their names, repositories); demo / demo-delete
#run demo::create / demo::delete in the app container, their options passed on (with Mailpit: its emails kept, --keep-emails)

set -euo pipefail

SELF_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# fetch_docker's download folder, removed at the end however it ends
FETCH_TMP=
trap 'rm -rf "${FETCH_TMP:-}"' EXIT
DOCKER_DIR="$SELF_DIR/docker"
ENV_FILE="$DOCKER_DIR/.env"
REPO_DEFAULT="https://github.com/serbanconstantinescu/qflows-core.git"
# the plugins --plugins can add: option name => the plugin's name, its repository
declare -A PLUGINS=(
    [regional-ro]="RegionalRO https://github.com/serbanconstantinescu/qflows-regional-ro.git"
    [regional-eu]="RegionalEU https://github.com/serbanconstantinescu/qflows-regional-eu.git"
)
#CLD: 2026-11-17T01:30:00+02:00: on by default (a new .env, or one from before the plugins' setting); their order (the associative array has none)
PLUGINS_DEFAULT=regional-ro,regional-eu
PLUGINS_ORDER=(regional-ro regional-eu)
BRANCH_DEFAULT="main"
# the repository's working tree (docker/ is its own, never downloaded)
CHECKOUT=0
[ -d "$SELF_DIR/.git" ] && [ -d "$SELF_DIR/app" ] && CHECKOUT=1

usage() {
    cat <<EOF
qflows with Docker Compose.  Usage: $(basename "$0") <command> [options]

Commands:
  install [options]    download docker/ (when it is not here), ask the settings, build the images, start
  update               download docker/ again (the settings kept), build the newest code, restart the containers
  setup [options]      ask the settings and write docker/.env; nothing is built or started
  build [service...]   build the images (all, or the ones named)
  start | stop | restart [service...]
  status               the settings in use and the containers
  logs [service...]    follow the logs (ctrl-c ends)
  check                run the check suite in the app container
  demo [--name=..] [--password=..]
                       make a demo account (demo::create: 4 projects, published workflows with past runs, open tasks, filled tables; no credential needed);
                       prints the account id and its users. With Mailpit its emails are kept: read them there
  demo-delete --account=<id>
                       delete a demo account and everything in it
  uninstall            remove the containers; the data (database, settings, logs, mail) is kept
  purge                remove the containers and all the data (asks first)
  help                 this text

Options (install, setup): what is not given is asked; the defaults are the current settings, else the first value:
  --mail=mailpit            the outgoing mail is caught by Mailpit (its page: http://127.0.0.1:8025), nothing is delivered
  --mail=host-haraka        your Haraka on this host sends the mail and receives the inbound mail (README.md: Inbound mail)
  --browser=yes|no          the web browser for the AI steps (playwright)
  --code=image              the app's code as cloned in the image
  --code=working-tree       development, in a clone of the repository only: its working tree mounted over the image's code
  --plugins=<list>|none     plugins cloned into the image, separated by commas (default: both): regional-ro (Romania: ANAF, BNR, e-Factura,
                            couriers, ...), regional-eu (VIES, ECB rates, TED tenders); the demo's Romanian and EU workflows need them. A change needs a build

HTTPS is yours: the site is on 127.0.0.1:8080 for your reverse proxy (README.md: Reverse proxy).
EOF
    exit "${1:-1}"
}

#option $1's value ($2) checked against the allowed ones ($3: a|b), printed as 1 (the second) / 0 (the first)
option_value() {
    local first=${3%%|*} second=${3#*|}
    case "$2" in
        "$first") echo 0 ;;
        "$second") echo 1 ;;
        *) echo "--$1: $2 is not one of: ${3//|/, }" >&2; exit 1 ;;
    esac
}

#--plugins' value checked: its names (regional-ro,...) without repeats, or none
plugin_list() {
    local out=() p
    [ "$1" != "none" ] || { echo none; return; }
    for p in ${1//,/ }; do
        [ -n "${PLUGINS[$p]:-}" ] || { echo "--plugins: $p is not one of: ${!PLUGINS[*]} (or none)" >&2; exit 1; }
        [[ " ${out[*]:-} " == *" $p "* ]] || out+=("$p")
    done
    [ "${#out[@]}" -gt 0 ] || { echo "--plugins: no plugin named (or none)" >&2; exit 1; }
    (IFS=,; echo "${out[*]}")
}

command="${1:-}"
[ -n "$command" ] && shift || true
# demo, demo-delete: their options are demo::create's / demo::delete's (passed on as they are)
DEMO_ARGS=()
if [ "$command" = "demo" ] || [ "$command" = "demo-delete" ]; then
    DEMO_ARGS=("$@")
    set --
fi
# empty: not given, setup asks (or takes .env's value, or the default); 1 / 0 otherwise
DEV=
BROWSER=
MAIL=
# the --plugins option's names (regional-ro,...), "none", or empty: not given
PLUGIN_LIST=
SERVICES=()
while [ "$#" -gt 0 ]; do
    case $1 in
        # --name value as --name=value
        --mail|--browser|--code|--plugins)
            [ "$#" -ge 2 ] || { echo "$1 needs a value (docker.sh help)"; exit 1; }
            set -- "$1=$2" "${@:3}"
            continue ;;
        --mail=*) MAIL=$(option_value mail "${1#*=}" "mailpit|host-haraka") ;;
        --browser=*) BROWSER=$(option_value browser "${1#*=}" "no|yes") ;;
        --code=*) DEV=$(option_value code "${1#*=}" "image|working-tree") ;;
        --plugins=*) PLUGIN_LIST=$(plugin_list "${1#*=}") ;;
        -h|--help) usage 0 ;;
        -*) echo "unknown option: $1 (docker.sh help)"; exit 1 ;;
        *) SERVICES+=("$1") ;;
    esac
    shift
done

need_docker() {
    command -v docker >/dev/null 2>&1 || { echo "docker is not installed"; exit 1; }
    docker compose version >/dev/null 2>&1 || { echo "the compose plugin is not installed (docker compose)"; exit 1; }
    if ! docker info >/dev/null 2>&1; then
        echo "the docker daemon is not reachable: as root, systemctl enable --now docker; usermod -aG docker $(id -un) (then log in again)"
        exit 1
    fi
}

need_env() {
    [ -f "$ENV_FILE" ] || { echo "no docker/.env: run $0 install (or setup) first"; exit 1; }
}

#a password for the connection strings: letters and digits only
password() {
    openssl rand -hex 24
}

#.env: key $1 set to $2 (the line replaced, or added)
set_env() {
    if grep -q "^$1=" "$ENV_FILE"; then
        sed -i "s|^$1=.*|$1=$2|" "$ENV_FILE"
    elif grep -q "^#$1=" "$ENV_FILE"; then
        sed -i "0,/^#$1=.*/s||$1=$2|" "$ENV_FILE"
    else
        echo "$1=$2" >> "$ENV_FILE"
    fi
}

get_env() {
    [ -f "$ENV_FILE" ] || return 0
    sed -n "s/^$1=//p" "$ENV_FILE" | tail -n1
}

#the repository and its branch: .env's, else the defaults (QFLOWS_REPO / QFLOWS_BRANCH in the environment override both)
repo() { echo "${QFLOWS_REPO:-$(get_env QFLOWS_REPO)}" | sed "s|^$|$REPO_DEFAULT|"; }
branch() { echo "${QFLOWS_BRANCH:-$(get_env QFLOWS_BRANCH)}" | sed "s|^$|$BRANCH_DEFAULT|"; }

#the branch's head commit: git ls-remote, else GitHub's API; empty when neither answers
head_commit() {
    local repo branch sha=""
    repo=$(repo)
    branch=$(branch)
    if command -v git >/dev/null 2>&1; then
        sha=$(git ls-remote "$repo" "refs/heads/$branch" 2>/dev/null | cut -f1 || true)
    fi
    if [ -z "$sha" ] && [[ "$repo" == https://github.com/* ]]; then
        local path=${repo#https://github.com/}
        sha=$(curl -fsSL "https://api.github.com/repos/${path%.git}/commits/$branch" 2>/dev/null | sed -n 's/^  "sha": "\([0-9a-f]\{40\}\)".*/\1/p' | head -n1 || true)
    fi
    echo "$sha"
}

#docker/ and scripts/mongo_users.js of commit $1 (else the branch) from GitHub's archive, next to this script; docker/.env kept. Not in a working tree
fetch_docker() {
    [ "$CHECKOUT" -eq 0 ] || return 0
    local repo ref
    repo=$(repo)
    ref=${1:-$(branch)}
    [[ "$repo" == https://github.com/* ]] || { echo "docker/ is downloaded from GitHub only (QFLOWS_REPO: $repo): put it next to $0 yourself"; exit 1; }
    command -v curl >/dev/null 2>&1 && command -v tar >/dev/null 2>&1 || { echo "curl and tar are needed to download docker/"; exit 1; }
    repo=${repo#https://github.com/}
    repo=${repo%.git}
    FETCH_TMP=$(mktemp -d)
    local tmp=$FETCH_TMP
    echo "downloading docker/ ($repo, $ref)"
    curl -fsSL "https://codeload.github.com/$repo/tar.gz/$ref" | tar -xz -C "$tmp" --strip-components=1 --wildcards '*/docker' '*/scripts/mongo_users.js'
    [ -f "$tmp/docker/compose.yaml" ] || { echo "the download has no docker/compose.yaml"; exit 1; }
    [ ! -f "$ENV_FILE" ] || cp -p "$ENV_FILE" "$tmp/docker/.env"
    rm -rf "$DOCKER_DIR"
    mv "$tmp/docker" "$DOCKER_DIR"
    mkdir -p "$SELF_DIR/scripts"
    mv "$tmp/scripts/mongo_users.js" "$SELF_DIR/scripts/mongo_users.js"
    rm -rf "$tmp"
}

#the commit the app image clones: the branch's head now (QFLOWS_COMMIT in .env); unknown (no network to GitHub): the branch, as cached
pin_commit() {
    local sha=$1
    [ -n "$sha" ] || { echo "the head of $(branch) is not known: the image clones the branch (a cached build keeps its code: docker.sh build --no-cache)"; }
    set_env QFLOWS_REPO "$(repo)"
    set_env QFLOWS_BRANCH "$(branch)"
    set_env QFLOWS_COMMIT "$sha"
    [ -z "$sha" ] || echo "  qflows: $(branch) at ${sha:0:12}"
}

# the questions: only on a terminal; read's prompt goes to stderr, the answer to stdout
INTERACTIVE=0
[ -t 0 ] && INTERACTIVE=1

#yes / no: $1 the question, $2 the default (1 / 0); prints 1 / 0
ask_yn() {
    local answer hint
    [ "$2" -eq 1 ] && hint="Y/n" || hint="y/N"
    while true; do
        read -r -p "$1 [$hint] " answer
        case "${answer,,}" in
            "") echo "$2"; return ;;
            y|yes) echo 1; return ;;
            n|no) echo 0; return ;;
        esac
    done
}

#variable $1 (an option's): kept when the option was given, else asked ($3, default $2) or the default
pick_yn() {
    [ -z "${!1}" ] || return 0
    if [ "$INTERACTIVE" -eq 1 ]; then
        printf -v "$1" '%s' "$(ask_yn "$3" "$2")"
    else
        printf -v "$1" '%s' "$2"
    fi
}

#.env key $1: asked ($3), the default .env's value when the file was there before, else $2; set in .env
pick_env() {
    local value
    value=$([ "$EXISTING" -eq 1 ] && get_env "$1" || true)
    value=${value:-$2}
    if [ "$INTERACTIVE" -eq 1 ]; then
        local answer
        read -r -p "$3 [${value:-none}] " answer
        value=${answer:-$value}
    fi
    set_env "$1" "$value"
}

#a port someone listens on here (this setup's own containers too, when they run)
port_warn() {
    command -v ss >/dev/null 2>&1 || return 0
    if ss -ltnH "sport = :$1" 2>/dev/null | grep -q .; then
        echo "  port $1 is in use here ($2): fine if these are this setup's containers, else choose another (setup again)"
    fi
}

#$1 in the comma / colon separated list $2
in_list() {
    [[ ",${2//:/,}," == *",$1,"* ]]
}

EXISTING=0

# the folders of compose.yaml's mounts in $1: the app's (config, logs, var, tmp) the containers' user's, the database's mongo's own
#(its entrypoint changes their owner); bounces: the host's Haraka writes, the app reads and removes (both); mailpit: its own user's
DATA_DIRS=(config logs var tmp bounces mongo-data mailpit)
make_data() {
    local d uid gid
    uid=$(get_env NFN_UID)
    gid=$(get_env NFN_GID)
    for d in "${DATA_DIRS[@]}"; do
        mkdir -p "$1/$d"
    done
    if [ "$(id -u)" -eq 0 ]; then
        chown "$uid:$gid" "$1" "$1"/{config,logs,var,tmp,bounces}
    fi
    chmod 700 "$1"
    chmod 777 "$1/bounces" "$1/mailpit"
    echo "  data: $1"
}

setup() {
    local files profiles=() owner_uid owner_gid smtp root_folder=0
    local d_dev=0 d_browser=1 d_mail=0

    [ -f "$DOCKER_DIR/compose.yaml" ] || fetch_docker
    cd "$DOCKER_DIR"

    # an existing .env gives the defaults: its compose files, its profiles, its values
    if [ -f "$ENV_FILE" ]; then
        EXISTING=1
        files=$(get_env COMPOSE_FILE)
        echo ".env found: its values are the defaults (the passwords are kept)"
        in_list compose.dev.yaml "$files" && d_dev=1
        in_list browser "$(get_env COMPOSE_PROFILES)" || d_browser=0
        in_list compose.mail.yaml "$files" && d_mail=1
    fi

    # development: the working tree's code mounted (compose.dev.yaml); downloaded alone there is none
    if [ "$CHECKOUT" -eq 0 ]; then
        [ "${DEV:-0}" -eq 0 ] || echo "  --code=working-tree needs a clone of the repository: the image's code"
        DEV=0
    fi
    pick_yn DEV "$d_dev" "Development: mount this clone's working tree over the image's code (--code=working-tree)?"
    pick_yn BROWSER "$d_browser" "The web browser for the AI steps (--browser=yes)?"
    pick_yn MAIL "$d_mail" "Mail through your Haraka on this host (--mail=host-haraka, README.md; no: Mailpit catches the outgoing mail)?"

    files="compose.yaml"
    [ "$DEV" -eq 0 ] || files="$files:compose.dev.yaml"
    [ "$MAIL" -eq 0 ] || files="$files:compose.mail.yaml"
    [ "$BROWSER" -eq 0 ] || profiles+=(browser)
    [ "$MAIL" -eq 1 ] || profiles+=(mailpit)
    profiles=$(IFS=,; echo "${profiles[*]:-}")

    if [ "$EXISTING" -eq 0 ]; then
        command -v openssl >/dev/null 2>&1 || { echo "openssl is needed for the passwords"; exit 1; }
        (umask 077; cp env.example "$ENV_FILE")
        set_env MONGO_ROOT_PW "$(password)"
        set_env NFN_APP_PW "$(password)"
        set_env NFN_HARAKA_PW "$(password)"
        echo ".env written (passwords generated; the database users are made from them at mongo's first start)"
    fi
    set_env COMPOSE_FILE "$files"
    set_env COMPOSE_PROFILES "$profiles"

    # the project's name (containers, volumes, images): the folder's, so two installations on one host (/home/dev, /home/app) stay apart
    pick_env COMPOSE_PROJECT_NAME "qflows-$(basename "$SELF_DIR" | tr 'A-Z' 'a-z' | tr -c 'a-z0-9_\n-' '-')" "The compose project's name (its containers, volumes, images)"

    # the containers' user: the folder's owner (root setting up another user's home: that user, not root; a folder of root's: 1000)
    owner_uid=$(stat -c %u "$SELF_DIR")
    owner_gid=$(stat -c %g "$SELF_DIR")
    [ "$owner_uid" -ne 0 ] || { owner_uid=1000; owner_gid=1000; root_folder=1; }
    pick_env NFN_UID "$owner_uid" "The containers' user id (the files' owner)"
    pick_env NFN_GID "$owner_gid" "The containers' group id"

    # the data (database, config, logs, mail) in folders of the user's home, made here with their owners (docker would make them root's)
    local home="" data
    # a folder of root's: the data next to this script (not uid 1000's home, maybe another user's)
    [ "$root_folder" -eq 1 ] || home=$(getent passwd "$(get_env NFN_UID)" | cut -d: -f6 || true)
    [ -n "$home" ] && [ -d "$home" ] || home=$SELF_DIR
    pick_env DATA_DIR "$home/docker-volumes" "The data's folder (the database, the config, the logs, the mail)"
    data=$(get_env DATA_DIR)
    case "$data" in
        /*) ;;
        *) data="$SELF_DIR/$data"; set_env DATA_DIR "$data" ;;
    esac
    make_data "$data"

    #the site for the operator's reverse proxy (HTTPS there); development: 127.0.0.1 always (compose.dev.yaml)
    [ "$DEV" -eq 1 ] || pick_env HTTP_BIND 127.0.0.1 "The site's address (127.0.0.1: the reverse proxy on this host)"
    pick_env HTTP_PORT 8080 "The site's port (the reverse proxy forwards to it)"
    port_warn "$(get_env HTTP_PORT)" "the site"
    pick_env BASE_URL "" "The public address, the reverse proxy's (e.g. https://flows.example.com; empty: asked by /setup)"

    #the plugins: the option's, else asked (the current ones the default), else the current ones; their names and repositories for the image.
    #.env without the setting (made before it): the default ones
    local current="" opt name url build="" names=""
    for opt in "${PLUGINS_ORDER[@]}"; do
        ! in_list "${PLUGINS[$opt]%% *}" "$(get_env NFN_PLUGINS)" || current="${current:+$current,}$opt"
    done
    grep -q '^NFN_PLUGINS=' "$ENV_FILE" || current=$PLUGINS_DEFAULT
    if [ -z "$PLUGIN_LIST" ] && [ "$INTERACTIVE" -eq 1 ]; then
        local answer
        read -r -p "Plugins to add, separated by commas: regional-ro (Romania: ANAF, BNR, e-Factura), regional-eu (VIES, ECB, TED); none [${current:-none}] " answer
        PLUGIN_LIST=$(plugin_list "${answer:-${current:-none}}")
    fi
    PLUGIN_LIST=${PLUGIN_LIST:-${current:-none}}
    if [ "$PLUGIN_LIST" != "none" ]; then
        for opt in ${PLUGIN_LIST//,/ }; do
            read -r name url <<< "${PLUGINS[$opt]}"
            build="${build:+$build }$name=$url"
            names="${names:+$names,}$name"
        done
    fi
    set_env QFLOWS_PLUGINS "\"$build\""
    set_env NFN_PLUGINS "$names"

    if in_list browser "$profiles"; then
        set_env NFN_BROWSER_URL "http://playwright:8931/mcp"
    else
        set_env NFN_BROWSER_URL ""
    fi
    #the app's SMTP server (the setup's default): mailpit, or the host's Haraka; an SMTP server of one's own (another name in .env) is kept
    smtp=$(get_env SMTP_HOST)
    if [ "$MAIL" -eq 1 ]; then
        #the host's Haraka: the ports it reaches (nfn.ini's uri and job_port); without it HARAKA_HOST empty (set, the Email (inbound) step would be offered)
        set_env HARAKA_HOST host.docker.internal
        pick_env MONGO_HOST_PORT 27017 "The database's port for Haraka (on 127.0.0.1)"
        port_warn "$(get_env MONGO_HOST_PORT)" "the database"
        pick_env DAEMON_HOST_PORT 9000 "The daemon's port for Haraka (on 127.0.0.1)"
        port_warn "$(get_env DAEMON_HOST_PORT)" "the daemon"
        case "$smtp" in ""|mailpit) set_env SMTP_HOST host.docker.internal; set_env SMTP_PORT 25 ;; esac
    else
        set_env HARAKA_HOST ""
        pick_env MAILPIT_PORT 8025 "Mailpit's page, the outgoing mail (on 127.0.0.1)"
        port_warn "$(get_env MAILPIT_PORT)" "mailpit"
        case "$smtp" in ""|host.docker.internal) set_env SMTP_HOST mailpit; set_env SMTP_PORT 1025 ;; esac
    fi

    # root setting up another user's folder: .env stays that user's (mode 600)
    if [ "$(id -u)" -eq 0 ] && [ "$(stat -c %u "$SELF_DIR")" -ne 0 ]; then
        chown "$(stat -c %u:%g "$SELF_DIR")" "$ENV_FILE"
    fi
    chmod 600 "$ENV_FILE"

    echo "  project: $(get_env COMPOSE_PROJECT_NAME)"
    echo "  files: $files"
    echo "  profiles: ${profiles:-(none)}"
    echo "  plugins: ${PLUGIN_LIST/none/(none)}"
    echo "  site: $(get_env HTTP_BIND):$(get_env HTTP_PORT) (HTTPS: your reverse proxy in front, README.md); containers' user $(get_env NFN_UID):$(get_env NFN_GID)"
    if [ "$MAIL" -eq 1 ]; then
        echo "  mail: your Haraka on this host (README.md, Inbound mail): database 127.0.0.1:$(get_env MONGO_HOST_PORT), daemon 127.0.0.1:$(get_env DAEMON_HOST_PORT), bounces $(get_env DATA_DIR)/bounces"
    else
        echo "  mail: caught by mailpit, http://127.0.0.1:$(get_env MAILPIT_PORT)/"
    fi
}

show_status() {
    need_env
    echo "files: $(get_env COMPOSE_FILE)"
    echo "profiles: $(get_env COMPOSE_PROFILES)"
    echo "qflows: $(get_env QFLOWS_BRANCH) at $(get_env QFLOWS_COMMIT | cut -c1-12)"
    local bind
    bind=$(get_env HTTP_BIND)
    #compose.dev.yaml publishes on 127.0.0.1 only
    [[ "$(get_env COMPOSE_FILE)" != *compose.dev.yaml* ]] || bind=127.0.0.1
    echo "site: http://${bind:-0.0.0.0}:$(get_env HTTP_PORT)/"
    ! in_list mailpit "$(get_env COMPOSE_PROFILES)" || echo "mail: http://127.0.0.1:$(get_env MAILPIT_PORT)/ (mailpit)"
    docker compose ps -a
}

# the commands but install and setup need docker/ (and run in it)
case "$command" in
    install|setup|"") ;;
    help|-h|--help) usage 0 ;;
    *) [ -f "$DOCKER_DIR/compose.yaml" ] || { echo "no docker/ next to $0: run $0 install first"; exit 1; }
       cd "$DOCKER_DIR" ;;
esac

case "$command" in
    install)
        need_docker
        # the commit first: docker/ downloaded at it, the image clones it
        sha=$(head_commit)
        [ -f "$DOCKER_DIR/compose.yaml" ] || fetch_docker "$sha"
        # setup each time (an existing .env: its values offered, kept on enter)
        setup
        pin_commit "$sha"
        docker compose build
        docker compose up -d
        docker compose ps
        echo "the site: port $(get_env HTTP_PORT), /setup on the first visit ($0 logs app daemon: the first start)"
        ;;
    update)
        need_docker
        need_env
        sha=$(head_commit)
        fetch_docker "$sha"
        cd "$DOCKER_DIR"
        pin_commit "$sha"
        docker compose build
        docker compose up -d
        docker compose ps
        ;;
    setup)
        setup
        ;;
    build)
        need_docker
        need_env
        docker compose build "${SERVICES[@]}"
        ;;
    start)
        need_docker
        need_env
        docker compose up -d "${SERVICES[@]}"
        ;;
    stop)
        need_docker
        need_env
        docker compose stop "${SERVICES[@]}"
        ;;
    restart)
        need_docker
        need_env
        docker compose restart "${SERVICES[@]}"
        ;;
    status)
        need_docker
        show_status
        ;;
    logs)
        need_docker
        need_env
        docker compose logs -f --tail=100 "${SERVICES[@]}"
        ;;
    check)
        need_docker
        need_env
        docker compose exec app app/v check::run
        ;;
    demo)
        need_docker
        need_env
        # Mailpit catches the demo's emails: kept for it to show
        keep=()
        ! in_list mailpit "$(get_env COMPOSE_PROFILES)" || keep=(--keep-emails)
        docker compose exec app app/v demo::create "${DEMO_ARGS[@]}" "${keep[@]}"
        ! in_list mailpit "$(get_env COMPOSE_PROFILES)" || echo "the demo's emails: http://127.0.0.1:$(get_env MAILPIT_PORT)/ (Mailpit)"
        ;;
    demo-delete)
        [[ " ${DEMO_ARGS[*]:-} " == *" --account="* ]] || { echo "usage: $0 demo-delete --account=<id> (the id demo printed)"; exit 1; }
        need_docker
        need_env
        docker compose exec app app/v demo::delete "${DEMO_ARGS[@]}"
        ;;
    uninstall)
        need_docker
        need_env
        docker compose down --remove-orphans
        echo "containers removed; the data (database, config, logs, mail in $(get_env DATA_DIR)) kept: $0 start brings it back"
        ;;
    purge)
        need_docker
        need_env
        read -r -p "remove the containers and every volume, the data's folders too ($(get_env DATA_DIR): the database, app.env with APP_SECRET, logs, mail)? type yes: " answer
        [ "$answer" = "yes" ] || { echo "nothing removed"; exit 1; }
        docker compose down --remove-orphans -v
        # the data's folders too (the database's files are mongo's user's: removed from a container, as root there)
        data=$(get_env DATA_DIR)
        if [ -n "$data" ] && [ -d "$data" ]; then
            docker run --rm -u 0 --entrypoint rm -v "$data:/data" mongo:8.3 -rf "${DATA_DIRS[@]/#//data/}"
            rmdir "$data" 2>/dev/null || true
            echo "removed: $data"
        fi
        ;;
    *)
        [ -z "$command" ] || echo "unknown command: $command"
        usage 1
        ;;
esac
