#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Runs the integration tests in tests/Integration against a freshly
# installed Nextcloud with the app and the public demo data (tools/demo):
#   bash tools/ci/integration-demo.sh <nextcloud-root>
#
# Requirements: Nextcloud installed, the app, the notifications app and the
# dav app (calendars) enabled, the catalog empty (the script refuses to run otherwise), the demo
# data built with `node tools/demo/build.mjs`. Run it as the user that owns
# the Nextcloud files. The script
#   1. creates a test account and its group,
#   2. assigns the roles (occ notenarchiv:setup),
#   3. copies the demo folder into the account's files and scans it,
#   4. sets the demo folder as archive folder,
#   5. imports the catalog (dry run, then for real) and runs the file sync,
#      checking the figures given in tools/demo/README.md (the two demo
#      transcription reports are read into review points here), and
#      switches on the interface for agents (module agent_api),
#   6. runs the integration tests,
#   7. runs an agent without a language model against the API for agents:
#      an account with the role "contributor" gets an app password, the MCP
#      server (tools/mcp) is started over stdio and its tools are called,
#      including the review of 1-1 and a new round of its demo report;
#      forbidden actions (deciding a review point among them) and the
#      switched-off interface must be refused. Afterwards the proposals are
#      rejected, the tasks discarded, the review points of the agent's
#      report dismissed, and the app password, the account and its group
#      are deleted.
#
# Step 7 needs Nextcloud behind a web server. Without NC_URL the script
# starts PHP's built-in server for the Nextcloud directory on 127.0.0.1
# (enough for these few sequential requests; Nextcloud always trusts
# 127.0.0.1) and stops it at the end. It also needs Node.js 24 and the MCP
# server built in tools/mcp (built here when tools/mcp/dist is missing).
#
# The person and performance lists are imported only by the tests, inside
# transactions that are rolled back. events_test expects no concert at all
# ("dry run leaves no events") and maintenance_test expects its dry run to
# create persons, so both must run before any real import of these lists;
# statistics_test imports the performance list itself, also rolled back.
#
# Environment (all optional):
#   CI_USER   test account, created by the script (default: demo-librarian)
#   CI_GROUP  its group, created by the script; holds the roles "librarian"
#             and "reader" (default: demo-archive)
#   DEMO_DIR  the built demo folder (default: build/demo/Notenarchiv-Demo)
#   PHP       PHP command (default: php)
#   AGENT_USER, AGENT_GROUP  account and group of the agent in step 7,
#             created and deleted by the script (default: demo-agent,
#             demo-agents)
#   NC_URL    URL of this Nextcloud for step 7; unset: PHP's built-in server
#             on 127.0.0.1:NC_PORT (default port 8080)
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
AGENT_USER="${AGENT_USER:-demo-agent}"
AGENT_GROUP="${AGENT_GROUP:-demo-agents}"
NC_PORT="${NC_PORT:-8080}"
TESTS="$REPO/tests/Integration"
MCP="$REPO/tools/mcp"
FOLDER="$(basename "$DEMO_DIR")"
IMPORT="$FOLDER/_Import"

WORK="$(mktemp -d)"
server_pid=""
cleanup() {
	if [ -n "$server_pid" ]; then
		kill "$server_pid" 2>/dev/null || true
	fi
	rm -rf "$WORK"
}
trap cleanup EXIT

# --no-warnings: without it occ prints global warnings on stdout ahead of the
# command's output, e.g. about the PCNTL extension, which the php:*-cli
# images of the CI lack; the script reads JSON reports and the data
# directory from that output.
occ() {
	"$PHP" -d memory_limit=1G "$NC_ROOT/occ" --no-warnings "$@"
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
for app in notenarchiv notifications dav; do
	if ! grep -q "^  - $app:" "$WORK/apps.txt"; then
		echo "The app $app is not enabled." >&2
		exit 2
	fi
done
for account in "$CI_USER" "$AGENT_USER"; do
	if occ user:info "$account" > /dev/null 2>&1; then
		echo "The account $account exists already; the script expects a fresh instance." >&2
		exit 2
	fi
done
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

step "2b. Modules"
# A new installation starts with the preset "standard"; the tests cover
# every function, so all modules are switched on. modules_test switches
# some off and on again and restores this state exactly.
occ notenarchiv:modules list
occ notenarchiv:modules preset complete

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
# 30 score and scan files plus the two transcription reports (1-1, 2-1)
expect file-sync.json new 32
expect file-sync.json missing 0
expect file-sync.json unassignable '["Mappe_4/4-04_Neuzugang-ohne-Eintrag.pdf"]'
# LIESMICH.txt
expect file-sync.json ignored 1
expect file-sync.json skippedFolders '["_Import"]'
expect file-sync.json copiesWithoutFile '["4-3"]'
# The reports "… - Bericht.json" count as reports, not as score files, and are
# read into review points (module transcription_review, on in "complete").
expect file-sync.json reports.files 2
expect file-sync.json reports.assigned 2
expect file-sync.json reports.imported 2
expect file-sync.json reports.failed '[]'
expect file-sync.json reports.switchedOff false
# The rights notices of the 7 MusicXML files (milestone 0.8): all name CC0 or
# the public domain; only 3-1 is marked protected, so one proposal, no status.
expect file-sync.json rightsNotices.read 7
expect file-sync.json rightsNotices.found 7
expect file-sync.json rightsNotices.proposals 1

step "5b. Interface for agents"
# Never part of a preset ("complete" leaves it off): switched on by itself.
occ notenarchiv:modules enable agent_api

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
# Creates its own calendar for the test account (dav app) and a concert;
# both are gone afterwards (rolled back).
run_test calendar_test
# 2-4 and 3-2 are two copies of the same print (Tischlied, edition by the
# uncertainly identified B. Beispiel, so no "free" proposal); all persons of
# 2-1 (Silcher, Dach, Herder) died more than 70 years ago.
run_test maintenance_test "$IMPORT/persons.csv" 2-4 3-2 2-1
# 2-6 is protected without a license for practice audio, 3-1 an internal
# preservation copy, 2-2 public domain but not confirmed.
run_test practice_audio_test 2-6 3-1 2-2
# Consistency of the statistics and the annual report with the performance
# records imported inside the test (rolled back); no fixed figures.
run_test statistics_test "$IMPORT/performances.csv"
# Concerts, the dashboard widget and rights switched off and on again
# (rolled back, module switches restored); 2-6 is protected without a
# license for practice audio.
run_test modules_test 2-6
# The API for agents through its controllers (1-1 public domain, 3-1 the
# internal preservation copy), with transcription reports the test writes
# itself; roles and modules restored exactly, everything else rolled back.
run_test api_v1_test 1-1 3-1
# Transcription review and a correction in the app on 1-2 (MXL, four
# staves), a copy without a demo report, so that the review points of the
# demo reports (1-1, 2-1) do not interfere. The test writes its report
# itself; the corrected file is deleted again, everything else rolled back.
run_test review_test 1-2
# Revisions of a version (milestone 0.8) on 1-2: the file sync records the
# first revision, a correction in the app the next one; the current revision,
# a concert confirmed as sung. The corrected file is deleted again, roles and
# module switches restored, everything else rolled back.
run_test version_states_test 1-2
# Opening from the Files app: a listed MusicXML file and PDF, an unlisted
# file (LIESMICH.txt), a file of a second account that the test creates and
# deletes, and the module switched off (rolled back, switches restored).
run_test files_integration_test
# Sharing beyond the archive roles (milestone 0.7): 1-3 is public domain with
# MusicXML (16 measures) and a PDF scan and not confirmed yet (excerpt, whole
# piece and scan for choir members and the public), 3-1 the internal
# preservation copy. The test creates a group and an account "member" and
# deletes them; role groups and module switches restored, the rest rolled
# back.
run_test sharing_test 1-3 3-1
# The folder book with 2-1 in a folder: issuing, taking back, the retention
# period with a staged day, the roles (rolled back, switches and role groups
# restored).
run_test folder_book_test 2-1
# The mirror folder of the print station with the scan of 2-3 (Am Brunnen
# vor dem Tore, confirmed as public domain inside the test): the target
# folder and everything written are deleted, the rest rolled back.
run_test print_mirror_test 2-3
# Milestone 0.8, archive: special cases of the protection term, the search
# of Nextcloud, gaps in the shelf marks, the rights notice of a MusicXML
# file and two annual snapshots with their comparison. Finds its copies
# itself; rolled back, module switches restored, snapshot folders deleted.
run_test archive_extras_test
run_test backup_test
# Creates two accounts in two groups of its own (role "reader"), shares the
# archive folder with the first group and gives the settings page to the
# second; all of it is removed again.
run_test settings_access_test

step "7. An agent without a language model: MCP server over stdio against the API for agents"
agent_failed=0
if [ ! -f "$MCP/dist/src/index.js" ]; then
	(cd "$MCP" && npm ci --no-audit --no-fund && npm run build)
fi
occ group:add "$AGENT_GROUP"
# Random login password, only passed through the environment, never printed;
# the agent signs in with an app password of its own (made in agent-demo.mjs).
agent_password="$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
OC_PASS="$agent_password" occ user:add --password-from-env --display-name="Notenarchiv CI agent" --group="$AGENT_GROUP" "$AGENT_USER"
occ notenarchiv:setup "$CI_USER" --role "contributor=$AGENT_GROUP"
if [ -z "${NC_URL:-}" ]; then
	NC_URL="http://127.0.0.1:$NC_PORT"
	"$PHP" -d memory_limit=1G -S "127.0.0.1:$NC_PORT" -t "$NC_ROOT" > "$WORK/php-server.log" 2>&1 &
	server_pid=$!
	for _ in $(seq 1 100); do
		# shellcheck disable=SC2016 # PHP code, not shell
		if "$PHP" -r 'exit(@file_get_contents($argv[1]) === false ? 1 : 0);' "$NC_URL/status.php"; then
			break
		fi
		sleep 0.2
	done
fi
if ! NOTENARCHIV_URL="$NC_URL" AGENT_USER="$AGENT_USER" AGENT_LOGIN_PASSWORD="$agent_password" NC_ROOT="$NC_ROOT" PHP="$PHP" SHELFMARK=1-1 \
	node "$MCP/ci/agent-demo.mjs"; then
	agent_failed=1
fi
unset agent_password
echo "-- cleanup: proposals rejected, tasks discarded, review points dismissed, account and group deleted"
if ! "$PHP" -d memory_limit=1G "$REPO/tools/ci/agent-demo-cleanup.php" "$NC_ROOT" "$CI_USER" "$AGENT_USER"; then
	agent_failed=1
fi
occ user:delete "$AGENT_USER"
occ group:delete "$AGENT_GROUP"
occ notenarchiv:setup "$CI_USER" --role contributor=
if [ -n "$server_pid" ]; then
	kill "$server_pid" 2>/dev/null || true
	server_pid=""
fi
if [ "$agent_failed" -ne 0 ]; then
	failed+=("agent-demo")
	if [ -f "$WORK/php-server.log" ]; then
		tail -n 20 "$WORK/php-server.log" || true
	fi
fi

echo
if [ "$check_failures" -gt 0 ] || [ "${#failed[@]}" -gt 0 ]; then
	echo "FAILED: $check_failures setup check(s), ${#failed[@]} test(s)${failed[*]:+: ${failed[*]}}"
	exit 1
fi
echo "all setup checks, integration tests and the agent run passed"
