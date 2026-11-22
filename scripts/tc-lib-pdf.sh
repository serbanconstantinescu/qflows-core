#!/bin/bash

#CLD: 2026-11-22T10:00:00+02:00: tc-lib-pdf (github.com/tecnickcom/tc-lib-pdf), the PDF library of the PDF document step (util\PdfDocument), installed without Composer in
#app/pkgs/tc-lib-pdf (not in git):
#  - tc-lib-pdf at its newest release tag (or --version), its tecnickcom/* dependencies (their composer.json, followed) each at the newest tag its constraint allows,
#    one folder per package (<package>/src ...)
#  - autoload.php: the packages' PSR-4 namespaces, K_PATH_FONTS = fonts/, K_PATH_CACHE = the system's temporary folder (the app requires only this file)
#  - fonts/: the families FONT_FAMILIES of tc-font-mirror (its newest tag) converted by tc-lib-pdf-font's own util/bulk_convert.php (licences checked, LICENSE + README
#    per family); core = the 14 standard PDF fonts (cp1252: no ș ț), pdfa = their embeddable copies (PDF/A)
#  - VERSIONS: what was installed
#Built next to it in app/pkgs/.tc-lib-pdf.new, then swapped in (a step running meanwhile keeps the old files). Run as the app's user (as root: the files get its owner).
#   scripts/tc-lib-pdf.sh install [--version=<x.y.z>]    install / update
#   scripts/tc-lib-pdf.sh status                         what is installed, and the latest release
#Needs curl (PHP), git (ls-remote, for the tags) and tar.

set -euo pipefail

SELF=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")
BASE=$(cd "$(dirname "$SELF")/.." && pwd)
APP_USER=$(stat -c %U "$BASE/app")
DEST=$BASE/app/pkgs/tc-lib-pdf
FONT_FAMILIES="core pdfa dejavu freefont"
VERSION=""

command="${1:-}"
[ -n "$command" ] && shift || true
while [ "$#" -gt 0 ]; do
    case $1 in
        --version=*) VERSION="${1#*=}" ;;
        *) echo "unknown option: $1"; exit 1 ;;
    esac
    shift
done

usage() {
    echo "usage: $0 {install [--version=<x.y.z>] | status}"
    exit 1
}

command -v php >/dev/null || { echo "php is not installed"; exit 1; }
command -v tar >/dev/null || { echo "tar is not installed"; exit 1; }
command -v git >/dev/null || { echo "git is not installed"; exit 1; }

#the PHP part: fetch <dir> <version> (download + autoload.php), latest (the newest release tag)
run_php() {
    php -d memory_limit=1G -- "$@" <<'PHP'
<?php
const OWNER = 'tecnickcom';

function fail($msg) { fwrite(STDERR, "error: $msg\n"); exit(1); }

function http($url) {
    $c = curl_init($url);
    curl_setopt_array($c, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTPHEADER => [ 'User-Agent: qflows-installer' ], CURLOPT_TIMEOUT => 600 ]);
    $body = curl_exec($c);
    $code = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    if ($body === false || $code !== 200)
        fail("GET $url: " . ($body === false ? curl_error($c) : "HTTP $code"));
    return $body;
}

//the release tags (x.y.z) of a repository, newest first (git ls-remote: GitHub's API would limit the requests)
function tags($repo) {
    exec('git ls-remote --tags --refs ' . escapeshellarg('https://github.com/' . OWNER . "/$repo.git") . ' 2>&1', $lines, $rc);
    if ($rc !== 0)
        fail("$repo: git ls-remote failed: " . implode(' ', $lines));
    $out = [];
    foreach ($lines as $l) {
        if (preg_match('~refs/tags/(v?\d+\.\d+\.\d+)$~', $l, $m))
            $out[] = $m[1];
    }
    usort($out, fn($a, $b) => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));
    return $out ?: fail("$repo: no release tags");
}

//whether version $v satisfies Composer constraint $c (^x.y[.z], ~x.y, >=x.y, x.y.z, *, alternatives with ||)
function satisfies($v, $c) {
    $v = ltrim($v, 'v');
    foreach (preg_split('/\s*\|\|?\s*/', trim($c)) as $alt) {
        if ($alt === '*' || $alt === '')
            return true;
        if (preg_match('/^\^(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $alt, $m)) {
            $min = $m[1] . '.' . ($m[2] ?? 0) . '.' . ($m[3] ?? 0);
            $max = (int)$m[1] > 0 ? ((int)$m[1] + 1) . '.0.0' : '0.' . ((int)($m[2] ?? 0) + 1) . '.0';
            if (version_compare($v, $min, '>=') && version_compare($v, $max, '<'))
                return true;
        } elseif (preg_match('/^~(\d+)\.(\d+)(?:\.(\d+))?$/', $alt, $m)) {
            $min = "$m[1].$m[2]." . ($m[3] ?? 0);
            $max = isset($m[3]) ? "$m[1]." . ((int)$m[2] + 1) . '.0' : ((int)$m[1] + 1) . '.0.0';
            if (version_compare($v, $min, '>=') && version_compare($v, $max, '<'))
                return true;
        } elseif (preg_match('/^>=\s*(\d+(?:\.\d+){0,2})$/', $alt, $m)) {
            if (version_compare($v, $m[1], '>='))
                return true;
        } elseif (preg_match('/^v?(\d+\.\d+\.\d+)$/', $alt, $m)) {
            if (version_compare($v, $m[1], '=='))
                return true;
        } else {
            fail("constraint `$c`: not understood");
        }
    }
    return false;
}

function extract_to($tgz, $dir) {
    @mkdir($dir, 0755, true);
    exec('tar xzf ' . escapeshellarg($tgz) . ' -C ' . escapeshellarg($dir) . ' --strip-components=1', $o, $rc);
    if ($rc !== 0)
        fail("can't extract $tgz");
}

function download($repo, $tag, $dir) {
    $tgz = tempnam(sys_get_temp_dir(), 'tclib');
    file_put_contents($tgz, http('https://codeload.github.com/' . OWNER . "/$repo/tar.gz/refs/tags/$tag"));
    extract_to($tgz, $dir);
    unlink($tgz);
}

[ , $cmd ] = $argv + [ 1 => '' ];

if ($cmd === 'latest') {
    echo tags('tc-lib-pdf')[0], "\n";
    exit(0);
}

if ($cmd === 'fetch') {
    [ , , $root, $version ] = $argv + [ 2 => '', 3 => '' ];
    if ($version === '')
        $version = tags('tc-lib-pdf')[0];
    //packages to install => their constraint (first seen); resolved => tag
    $queue = [ 'tc-lib-pdf' => $version ];
    $resolved = [];
    $psr4 = [];
    while ($queue) {
        $repo = array_key_first($queue);
        $want = array_shift($queue);
        if ($repo === 'tc-lib-pdf') {
            $tag = $want;
        } else {
            $tag = null;
            foreach (tags($repo) as $t) {
                if (satisfies($t, $want)) {
                    $tag = $t;
                    break;
                }
            }
            if ($tag === null)
                fail("$repo: no tag satisfies $want");
        }
        echo "  $repo $tag\n";
        download($repo, $tag, "$root/$repo");
        $resolved[$repo] = $tag;
        $composer = json_decode((string)@file_get_contents("$root/$repo/composer.json"), true) ?: fail("$repo: no composer.json");
        foreach ($composer['autoload']['psr-4'] ?? [] as $ns => $path)
            $psr4[$ns] = "$repo/" . trim((string)$path, '/');
        foreach ($composer['require'] ?? [] as $pkg => $constraint) {
            if (!str_contains($pkg, '/'))
                continue;
            [ $vendor, $name ] = explode('/', $pkg, 2);
            if ($vendor !== OWNER)
                fail("$repo requires $pkg: only " . OWNER . '/* packages are installed by this script');
            if (!isset($resolved[$name]) && !isset($queue[$name]))
                $queue[$name] = $constraint;
        }
    }

    //the longest namespace first: Com\Tecnick\Pdf\Font\ before Com\Tecnick\Pdf\; a class not found there is looked for in the shorter ones too
    //(tc-lib-unicode has classes of Com\Tecnick\Unicode\Data\, tc-lib-unicode-data's namespace)
    uksort($psr4, fn($a, $b) => strlen($b) <=> strlen($a));
    $map = var_export($psr4, true);
    $list = implode(', ', array_map(fn($r, $t) => "$r $t", array_keys($resolved), $resolved));
    file_put_contents("$root/autoload.php", <<<PHPCODE
<?php
//written by scripts/tc-lib-pdf.sh: $list
if (!defined('K_PATH_FONTS'))
    define('K_PATH_FONTS', __DIR__ . '/fonts/');
//its temporary files where the app keeps its own (tc-lib-file would take php.ini's upload_tmp_dir first)
if (!defined('K_PATH_CACHE'))
    define('K_PATH_CACHE', rtrim(sys_get_temp_dir(), '/') . '/');
spl_autoload_register(static function (\$class) {
    static \$map = $map;
    foreach (\$map as \$ns => \$dir) {
        if (strncmp(\$class, \$ns, strlen(\$ns)) === 0) {
            \$file = __DIR__ . '/' . \$dir . '/' . str_replace('\\\\', '/', substr(\$class, strlen(\$ns))) . '.php';
            if (is_file(\$file)) {
                require \$file;
                return;
            }
        }
    }
});

PHPCODE);
    $versions = '';
    foreach ($resolved as $r => $t)
        $versions .= "$r $t\n";
    file_put_contents("$root/VERSIONS", $versions);

    //the font sources: the newest tag of tc-font-mirror
    $mirror = tags('tc-font-mirror')[0];
    echo "  tc-font-mirror $mirror (fonts)\n";
    download('tc-font-mirror', $mirror, "$root/.font-mirror");
    file_put_contents("$root/VERSIONS", "tc-font-mirror $mirror\n", FILE_APPEND);
    exit(0);
}

fail("unknown command $cmd");
PHP
}

case $command in
    install)
        [ "$(id -u)" -eq 0 ] || [ "$(id -un)" = "$APP_USER" ] || { echo "run it as $APP_USER (or root)"; exit 1; }
        NEW=$BASE/app/pkgs/.tc-lib-pdf.new
        OLD=$BASE/app/pkgs/.tc-lib-pdf.old
        rm -rf "$NEW" "$OLD"
        mkdir -p "$NEW"
        trap 'rm -rf "$NEW"' EXIT
        echo "tc-lib-pdf ${VERSION:-(latest)} and its packages:"
        run_php fetch "$NEW" "$VERSION"

        echo "fonts: $FONT_FAMILIES"
        #only the families wanted, as bulk_convert.php converts every folder of its input
        mkdir -p "$NEW/.font-src"
        for f in $FONT_FAMILIES; do
            [ -d "$NEW/.font-mirror/$f" ] || { echo "error: tc-font-mirror has no $f"; exit 1; }
            mv "$NEW/.font-mirror/$f" "$NEW/.font-src/$f"
        done
        rm -rf "$NEW/.font-mirror"
        #bulk_convert.php wants a Composer autoloader next to it: ours, for the run only
        FONTPKG=$NEW/tc-lib-pdf-font
        mkdir -p "$FONTPKG/vendor/composer"
        echo "<?php require '$NEW/autoload.php';" > "$FONTPKG/vendor/autoload.php"
        echo '{"packages":[]}' > "$FONTPKG/vendor/composer/installed.json"
        rc=0
        php -d memory_limit=1G "$FONTPKG/util/bulk_convert.php" --indir="$NEW/.font-src" --outpath="$NEW/fonts" > "$NEW/.fonts.log" 2>&1 || rc=$?
        grep -v '+++ OK' "$NEW/.fonts.log" | sed '/^\s*$/d' || true
        [ "$rc" -eq 0 ] || { echo "error: the fonts were not all converted"; exit 1; }
        rm -f "$NEW/.fonts.log"
        rm -rf "$FONTPKG/vendor" "$NEW/.font-src"

        #a test: a one-page PDF with DejaVu Sans (Romanian diacritics)
        php -r 'require $argv[1] . "/autoload.php"; $p = new \Com\Tecnick\Pdf\Tcpdf("mm"); $f = $p->font->insert($p->pon, "dejavusans", "", 10);
            $p->addPage(); $p->page->addContent($f["out"]); $p->addHTMLCell("<p>Test ăâîșț</p>", 15, 15, 100);
            $s = $p->getOutPDFString(); if (strncmp($s, "%PDF-", 5) !== 0) exit(1); echo "test: a PDF of ", strlen($s), " bytes\n";' "$NEW" \
            || { echo "error: the test PDF failed"; exit 1; }

        [ "$(id -u)" -eq 0 ] && chown -R "$APP_USER:$APP_USER" "$NEW"
        [ -d "$DEST" ] && mv "$DEST" "$OLD"
        mv "$NEW" "$DEST"
        rm -rf "$OLD"
        trap - EXIT
        echo "installed in $DEST:"
        sed 's/^/  /' "$DEST/VERSIONS"
        ;;
    status)
        if [ -f "$DEST/VERSIONS" ]; then
            echo "installed in $DEST:"
            sed 's/^/  /' "$DEST/VERSIONS"
        else
            echo "not installed ($DEST)"
        fi
        echo "newest tc-lib-pdf release: $(run_php latest)"
        ;;
    *)
        usage
        ;;
esac
