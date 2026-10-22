#!/usr/bin/env bash
# Runs the integration tests in tests/Integration against a freshly
# installed Nextcloud with the app and the public demo data (tools/demo):
#   bash tools/ci/integration-demo.sh <nextcloud-root>
#
# Requirements: Nextcloud installed, the app and the notifications app
# enabled, the catalog empty (the script refuses to run otherwise), the demo
# data built with `node tools/demo/build.mjs`. Run it as the user that owns
# the Nextcloud files. The script
#   1. creates a test account and its group,
#   2. assigns the roles (occ notenarchiv:setup),
#   3. copies the demo folder into the account's files and scans it,
#   4. sets the demo folder as archive folder,
#   5. imports the catalog (dry run, then for real) and runs the file sync,
#      checking the figures given in tools/demo/README.md,
#   6. runs the integration tests.
#
# The person and performance lists are imported only by the tests, inside
# transactions that are rolled back. events_test expects no concert at all
# ("dry run leaves no events") and maintenance_test expects its dry run to
# create persons, so both must run before any real import of these lists.
#
# Environment (all optional):
#   CI_USER   test account, created by the script (default: demo-librarian)
#   CI_GROUP  its group, created by the script; holds the roles "librarian"
#             and "reader" (default: demo-archive)
#   DEMO_DIR  the built demo folder (default: build/demo/Notenarchiv-Demo)
#   PHP       PHP command (default: php)
#
# Ends with exit code 0 only if every check and every test passed; a failed
# test prints its failing lines.
set -euo pipefail

if [ $# -ne 1 ]; then
	echo "usage: $0 <nextcloud-root>" >&2
	exit 2
fi
NC_ROOT="$(cd "$1" && pwd)"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
CI_USER="${CI_USER:-demo-librarian}"
CI_GROUP="${CI_GROUP:-demo-archive}"
DEMO_DIR="${DEMO_DIR:-$REPO/build/demo/Notenarchiv-Demo}"
PHP="${PHP:-php}"
TESTS="$REPO/tests/Integration"
FOLDER="$(basename "$DEMO_DIR")"
IMPORT="$FOLDER/_Import"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

occ() {
	"$PHP" -d memory_limit=1G "$NC_ROOT/occ" "$@"
}

step() {
	printf '\n== %s\n' "$*"
}

# Compares one value of a JSON report (path with dots, e.g.
# distribution.rightsStatus) with the expected JSON value. Objects are
# compared without regard to the order of their keys.
check_failures=0
expect() {
	local file=$1 key=$2 expected=$3 actual
	# shellcheck disable=SC2016 # PHP code, not shell
	if actual="$("$PHP" -r '
		$text = (string)file_get_contents($argv[1]);
		// occ may print notices before the JSON report
		$start = strpos($text, "{");
		$value = $start === false ? null : json_decode(substr($text, $start, (int)strrpos($text, "}") - $start + 1), true);
		foreach (explode(".", $argv[2]) as $part) {
			$value = is_array($value) && array_key_exists($part, $value) ? $value[$part] : null;
		}
		$normalize = function (mixed $v) use (&$normalize): mixed {
			if (!is_array($v)) {
				return $v;
			}
			if (!array_is_list($v)) {
				ksort($v);
			}
			return array_map($normalize, $v);
		};
		echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit($normalize($value) === $normalize(json_decode($argv[3], true)) ? 0 : 1);
	' -- "$WORK/$file" "$key" "$expected")"; then
		echo "ok   $file: $key = $actual"
	else
		echo "FAIL $file: $key = $actual, expected $expected"
		check_failures=$((check_failures + 1))
	fi
}

if [ ! -f "$DEMO_DIR/_Import/catalog.csv" ]; then
	echo "No demo data in $DEMO_DIR; build it with: node tools/demo/build.mjs" >&2
	exit 2
fi

step "0. Preconditions"
occ status
occ app:list --enabled > "$WORK/apps.txt"
for app in notenarchiv notifications; do
	if ! grep -q "^  - $app:" "$WORK/apps.txt"; then
		echo "The app $app is not enabled." >&2
		exit 2
	fi
done
if occ user:info "$CI_USER" > /dev/null 2>&1; then
	echo "The account $CI_USER exists already; the script expects a fresh instance." >&2
	exit 2
fi
occ notenarchiv:export --full > "$WORK/backup.json"
# shellcheck disable=SC2016 # PHP code, not shell
"$PHP" -r '
	$backup = json_decode((string)file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
	$filled = array_keys(array_filter($backup["tables"], fn (array $rows): bool => $rows !== []));
	if ($filled !== []) {
		fwrite(STDERR, "The catalog is not empty (" . implode(", ", $filled) . "); the script expects a fresh instance.\n");
		exit(2);
	}
	echo "catalog empty: " . count($backup["tables"]) . " tables\n";
' -- "$WORK/backup.json"
rm "$WORK/backup.json"

step "1. Test account $CI_USER in group $CI_GROUP"
occ group:add "$CI_GROUP"
# Random password, only passed through the environment, never printed.
password="$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
OC_PASS="$password" occ user:add --password-from-env --display-name="Notenarchiv CI" --group="$CI_GROUP" "$CI_USER"
unset password

step "2. Roles"
# The account holds the catalog role and the read role only, so that the
# tests can check what a librarian without further roles may not do.
occ notenarchiv:setup "$CI_USER" --role "librarian=$CI_GROUP" --role "reader=$CI_GROUP" \
	--role conductor= --role operations= --role contributor=

step "3. Demo data in the files of $CI_USER"
datadir="$(occ config:system:get datadirectory)"
if [ -e "$datadir/$CI_USER/files/$FOLDER" ]; then
	echo "$datadir/$CI_USER/files/$FOLDER exists already." >&2
	exit 2
fi
mkdir -p "$datadir/$CI_USER/files"
cp -r "$DEMO_DIR" "$datadir/$CI_USER/files/$FOLDER"
occ files:scan --path="/$CI_USER/files/$FOLDER"

step "4. Archive folder"
occ notenarchiv:setup "$CI_USER" "$FOLDER"

step "5. Catalog import and file sync (figures from tools/demo/README.md)"
occ notenarchiv:import "$CI_USER" "$IMPORT/catalog.csv" > "$WORK/import-dry-run.json"
occ notenarchiv:import --execute "$CI_USER" "$IMPORT/catalog.csv" > "$WORK/import.json"
for report in import-dry-run.json import.json; do
	expect "$report" rows 23
	# All 23 rows create a copy also in the real run: the dry run left nothing.
	expect "$report" created 23
	expect "$report" withMusicxml 7
	expect "$report" preservationCopies 1
	expect "$report" distribution.rightsStatus '{"public_domain": 19, "protected": 2, "open": 2}'
	expect "$report" warnings '[]'
done
expect import-dry-run.json dryRun true
expect import.json dryRun false

occ notenarchiv:file-sync "$CI_USER" > "$WORK/file-sync.json"
expect file-sync.json files 30
expect file-sync.json byType '{"pdf": 23, "mxl": 4, "musicxml": 3}'
expect file-sync.json assigned 29
expect file-sync.json new 30
expect file-sync.json missing 0
expect file-sync.json unassignable '["Mappe_4/4-04_Neuzugang-ohne-Eintrag.pdf"]'
# LIESMICH.txt
expect file-sync.json ignored 1
expect file-sync.json skippedFolders '["_Import"]'
expect file-sync.json copiesWithoutFile '["4-3"]'

step "6. Integration tests"
failed=()
run_test() {
	local name=$1 log="$WORK/$1.log"
	shift
	echo "=== $name $*"
	if "$PHP" -d memory_limit=1G "$TESTS/$name.php" "$NC_ROOT" "$CI_USER" "$@" > "$log" 2>&1; then
		echo "passed: $(grep -c '^ok' "$log" || true) checks"
	else
		failed+=("$name")
		grep -v '^ok' "$log" | tail -n 40 || true
	fi
}
# A = 2-2 is confirmed as public domain and released, B = 1-3 stays unconfirmed.
run_test workflow_test 2-2 1-3
run_test notifications_test 2-1
run_test tasks_links_test 1-2
# A = 2-1 is part of the current repertoire (sung 2022 to 2026); the first
# ambiguous programme line ("Der Mond ist aufgegangen", 1-1 or 3-1) is
# resolved to B = 3-1.
run_test events_test "$IMPORT/performances.csv" "$IMPORT/song-lists.csv" 2-1 3-1
# 2-4 and 3-2 are two copies of the same print (Tischlied, edition by the
# uncertainly identified B. Beispiel, so no "free" proposal); all persons of
# 2-1 (Silcher, Dach, Herder) died more than 70 years ago.
run_test maintenance_test "$IMPORT/persons.csv" 2-4 3-2 2-1
# 2-6 is protected without a license for practice audio, 3-1 an internal
# preservation copy, 2-2 public domain but not confirmed.
run_test practice_audio_test 2-6 3-1 2-2
run_test backup_test

echo
if [ "$check_failures" -gt 0 ] || [ "${#failed[@]}" -gt 0 ]; then
	echo "FAILED: $check_failures setup check(s), ${#failed[@]} test(s)${failed[*]:+: ${failed[*]}}"
	exit 1
fi
echo "all setup checks and integration tests passed"
