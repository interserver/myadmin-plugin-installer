#!/usr/bin/env bash
#
# Runs the whole plugin fleet against THIS checkout of the installer.
#
# ---------------------------------------------------------------------------------
# WHAT THIS ANSWERS THAT NOTHING ELSE DOES
# ---------------------------------------------------------------------------------
# Every one of the 69 plugin repos extends this package's base classes, and every one of
# them runs its own CI against whatever version of this package Packagist happens to serve.
# So a change here that breaks them is invisible in this repo -- its own suite stays green,
# the tag ships, and the breakage surfaces one repo at a time, days later, to somebody who
# changed nothing.
#
# This clones the fleet, forces each clone to resolve the installer to the working tree
# rather than to a release, and runs each suite. A red row here means "this installer change
# breaks that package", which is a sentence no other test in this repository can produce.
#
# ---------------------------------------------------------------------------------
# THE ISOLATION THAT MATTERS
# ---------------------------------------------------------------------------------
# The path repository is registered with "symlink": false, so composer COPIES this package
# into each clone. With the default symlink, every clone's vendor/ points back at the same
# source tree -- and the run then proves nothing about the copy, because there is only ever
# one copy. That failure mode is silent and reads as a pass. `--verify-isolation` plants a
# deliberate break in the copied harness and asserts the fleet notices.
#
# ---------------------------------------------------------------------------------
# USAGE
# ---------------------------------------------------------------------------------
#   tools/fleet-test.sh                        every repo in tools/fleet-repos.json
#   tools/fleet-test.sh --limit=5              first N (smoke test)
#   tools/fleet-test.sh --only=kvm-vps,ssl-module
#
# A manifest "repo" may be an absolute path instead of owner/name, which is how a planted
# break is proven against a local checkout rather than by pushing a broken branch.
#   tools/fleet-test.sh --jobs=4               N repos at a time
#   tools/fleet-test.sh --work=/path/to/dir    keep the checkouts
#   tools/fleet-test.sh --verify-isolation     prove a planted break is detected, then stop
#
# Exit: 0 nothing NEW is red · 1 a repo went red that tools/fleet-baseline.json does not
# already account for (or a baselined repo started passing, which makes the entry stale) ·
# 2 the runner itself broke.
#
# A repo that fails to clone or install counts as failing. Unattended jobs that treat
# infrastructure trouble as "not my problem" go green for months.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MANIFEST="$ROOT/tools/fleet-repos.json"
BASELINE="$ROOT/tools/fleet-baseline.json"
WORK="${TMPDIR:-/tmp}/fleet-$$"
LIMIT=0
ONLY=""
JOBS=1
VERIFY=0
KEEP=0

for arg in "$@"; do
  case "$arg" in
    --limit=*) LIMIT="${arg#*=}" ;;
    --only=*)  ONLY="${arg#*=}" ;;
    --jobs=*)  JOBS="${arg#*=}" ;;
    --work=*)  WORK="${arg#*=}"; KEEP=1 ;;
    --verify-isolation) VERIFY=1 ;;
    *) echo "unknown argument: $arg" >&2; exit 2 ;;
  esac
done

command -v composer >/dev/null || { echo "composer is not on PATH" >&2; exit 2; }
[ -f "$MANIFEST" ] || { echo "missing $MANIFEST" >&2; exit 2; }

# ---------------------------------------------------------------------------
# The fleet, from the committed manifest rather than from whatever happens to be
# on this disk -- so the job is reproducible on a runner that has no core checkout.
# ---------------------------------------------------------------------------
mapfile -t ENTRIES < <(php -r '
$m = json_decode(file_get_contents($argv[1]), true);
foreach ($m["repos"] as $r) {
    echo $r["repo"], " ", $r["branch"], " ", $r["package"], "\n";
}' "$MANIFEST")

if [ -n "$ONLY" ]; then
  IFS=',' read -r -a want <<<"$ONLY"
  filtered=()
  for e in "${ENTRIES[@]}"; do
    name="${e%% *}"; name="${name##*/}"
    for w in "${want[@]}"; do [ "$name" = "$w" ] && filtered+=("$e"); done
  done
  ENTRIES=("${filtered[@]}")
fi
[ "$LIMIT" -gt 0 ] && ENTRIES=("${ENTRIES[@]:0:$LIMIT}")
[ "${#ENTRIES[@]}" -eq 0 ] && { echo "no repos selected" >&2; exit 2; }

mkdir -p "$WORK/repos" "$WORK/logs" "$WORK/results"

# A pristine copy of the installer, so a planted break in --verify-isolation cannot
# escape into the developer's actual working tree.
SUBJECT="$WORK/installer"
mkdir -p "$SUBJECT"
tar -C "$ROOT" --exclude=.git --exclude=vendor --exclude=.phpunit.cache -cf - . | tar -C "$SUBJECT" -xf -

# The copy has no .git, so composer cannot infer a version for it and calls it dev-main --
# which satisfies nothing. Two packages in this fleet depend on a SIBLING plugin, and that
# sibling requires the installer at ^2.1; against an unversioned path repo those two fail to
# install with a message about repository priority that says nothing about the real cause.
# Declaring the version is what makes the copy stand in for a release rather than for a
# branch nobody requires.
VERSION="$(git -C "$ROOT" describe --tags --abbrev=0 2>/dev/null | sed 's/^v//')"
if [ -z "$VERSION" ]; then
  echo "  cannot determine a version for the working tree (no reachable tag)" >&2
  exit 2
fi
echo "  installer under test: $VERSION (working tree)"

if [ "$VERIFY" -eq 1 ]; then
  # A break every single package must notice, planted in the ONE method every generated
  # ContractTest runs. Chosen over mutating an inspector because no knowledge of any
  # inspector's internals is needed for it to be universal -- if a run stays green after
  # this, the clones are reading the original tree and every green row in a normal run is
  # meaningless.
  php -r '
$f = $argv[1];
$s = file_get_contents($f);
$needle = "    public function testPluginSatisfiesContractAssertion(\$inspectorClass)\n    {\n";
if (strpos($s, $needle) === false) {
    fwrite(STDERR, "isolation canary could not be planted: driver signature changed\n");
    exit(1);
}
file_put_contents($f, str_replace(
    $needle,
    $needle."        \$this->fail(\x27HARNESS ISOLATION CANARY\x27);\n",
    $s,
    $n
));' "$SUBJECT/src/Testing/PluginContractTestCase.php" || exit 2
  echo "  !! isolation check: a forced failure was planted in the copied harness"
fi

echo "  Fleet run against $(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo 'working tree')"
echo "  --------------------------------------------------------------"
echo "  repos    : ${#ENTRIES[@]}"
echo "  jobs     : $JOBS"
echo "  work dir : $WORK"
echo "  --------------------------------------------------------------"

run_one() {
  local slug branch package name dest log out status line config
  slug="$1"; branch="$2"; package="$3"
  name="${slug##*/}"
  dest="$WORK/repos/$name"
  log="$WORK/logs/$name.log"
  out="$WORK/results/$name.json"
  status="unknown"

  # A manifest entry is normally owner/name and clones from GitHub. An absolute path is
  # accepted too, so a planted break can be proven against a local checkout without pushing
  # a deliberately broken branch to a real repository -- which is how gate G8 is reproduced.
  local url
  case "$slug" in
    /*|*://*) url="$slug" ;;
    *)        url="https://github.com/$slug.git" ;;
  esac
  {
    git clone --quiet --depth 1 --branch "$branch" "$url" "$dest"
  } >>"$log" 2>&1

  if [ ! -d "$dest" ]; then
    status="clone_failed"
  else
    # symlink:false is load-bearing -- see the header. --no-plugins because the package
    # being installed IS a composer plugin, and activating 69 copies of it unattended is
    # neither needed to run a suite nor safe.
    composer config repositories.harness \
      "{\"type\":\"path\",\"url\":\"$SUBJECT\",\"options\":{\"symlink\":false,\"versions\":{\"detain/myadmin-plugin-installer\":\"$VERSION\"}}}" \
      --working-dir="$dest" >>"$log" 2>&1
    composer config --no-plugins allow-plugins.detain/myadmin-plugin-installer false \
      --working-dir="$dest" >>"$log" 2>&1

    if composer require "detain/myadmin-plugin-installer:$VERSION" \
         --working-dir="$dest" --no-interaction --no-progress --no-plugins --no-scripts \
         --with-all-dependencies >>"$log" 2>&1; then
      config="phpunit.xml.dist"
      [ -f "$dest/phpunit.xml.dist" ] || config="phpunit.xml"
      if [ ! -f "$dest/$config" ]; then
        status="no_phpunit_config"
      elif [ -x "$dest/vendor/bin/phpunit" ]; then
        # Both the subshell and --configuration are required. PHPUnit resolves a relative
        # bootstrap against the working directory and, given no --configuration, discovers
        # one by searching the working directory -- which is this repo. Without these it
        # cheerfully runs the INSTALLER's own suite once per package and reports 69 passes
        # that say nothing about any plugin.
        if (cd "$dest" && ./vendor/bin/phpunit --configuration "$config" \
             --do-not-cache-result --no-coverage) >>"$log" 2>&1; then
          status="pass"
        else
          status="fail"
        fi
      else
        status="no_phpunit"
      fi
    else
      status="install_failed"
    fi
  fi

  line="$(grep -Eo 'Tests: [0-9]+, Assertions: [0-9]+[^.]*' "$log" | tail -1)"
  printf '{"repo":"%s","package":"%s","status":"%s","summary":"%s"}\n' \
    "$name" "$package" "$status" "$line" >"$out"
  printf '  %-36s %s\n' "$name" "$status"
}
export -f run_one
export WORK SUBJECT VERSION

if [ "$JOBS" -gt 1 ]; then
  printf '%s\n' "${ENTRIES[@]}" | xargs -P "$JOBS" -L1 bash -c 'run_one "$0" "$1" "$2"'
else
  for e in "${ENTRIES[@]}"; do
    # shellcheck disable=SC2086
    run_one $e
  done
fi

# ---------------------------------------------------------------------------
# Report
# ---------------------------------------------------------------------------
{
  echo "| Repo | Status | Summary |"
  echo "|---|---|---|"
  for f in "$WORK"/results/*.json; do
    [ -f "$f" ] || continue
    php -r '$r = json_decode(file_get_contents($argv[1]), true);
      printf("| %s | %s | %s |\n", $r["repo"], $r["status"] === "pass" ? "pass" : "**".$r["status"]."**", $r["summary"]);' "$f"
  done
} >"$WORK/matrix.md"

pass=$(grep -l '"status":"pass"' "$WORK"/results/*.json 2>/dev/null | wc -l)
total=${#ENTRIES[@]}
bad=$(( total - pass ))

echo "  --------------------------------------------------------------"
echo "  pass=$pass  not-pass=$bad  of $total"
echo "  matrix: $WORK/matrix.md"
[ -n "${GITHUB_STEP_SUMMARY:-}" ] && cat "$WORK/matrix.md" >>"$GITHUB_STEP_SUMMARY"

# ---------------------------------------------------------------------------
# The gate: NEW red, not red.
#
# Nine repos are already red for reasons that have nothing to do with this package --
# owner-deferred P-bugs and pre-existing suites, verified against the released v2.2.1. A job
# that failed on those would be red every week from the day it was switched on, and a job
# that is always red is a job nobody reads. So the signal is the difference.
#
# A baselined repo that turns GREEN is reported too: the entry is then stale, and a stale
# entry silently exempts a repo that has started passing.
# ---------------------------------------------------------------------------
NEW_RED=""
FIXED=""
if [ -f "$BASELINE" ]; then
  for f in "$WORK"/results/*.json; do
    [ -f "$f" ] || continue
    repo="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["repo"];' "$f")"
    st="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["status"];' "$f")"
    known="$(php -r '$b = json_decode(file_get_contents($argv[1]), true);
      echo isset($b["known_red"][$argv[2]]) ? "1" : "0";' "$BASELINE" "$repo")"
    if [ "$st" != "pass" ] && [ "$known" = "0" ]; then
      NEW_RED="$NEW_RED $repo"
    elif [ "$st" = "pass" ] && [ "$known" = "1" ]; then
      FIXED="$FIXED $repo"
    fi
  done
fi

[ -n "$FIXED" ] && {
  echo "  STALE BASELINE — these are listed as known-red but passed:$FIXED"
  echo "  Remove them from tools/fleet-baseline.json; while listed, a real regression in them is exempt."
}

if [ "$VERIFY" -eq 1 ]; then
  [ "$KEEP" -eq 0 ] && rm -rf "$WORK"
  if [ "$bad" -eq 0 ]; then
    echo "  ISOLATION CHECK FAILED: a renamed inspector changed nothing, so the clones are not"
    echo "  reading the copied harness. Every green row in a normal run is meaningless."
    exit 1
  fi
  echo "  isolation check passed: the planted break was detected by $bad of $total repos"
  exit 0
fi

if [ -n "$NEW_RED" ]; then
  echo "  NEW FAILURES (not in the baseline):$NEW_RED"
  echo "  logs kept: $WORK/logs/"
  exit 1
fi

if [ "$bad" -gt 0 ]; then
  echo "  all $bad failing repos are baselined; see tools/fleet-baseline.json for why"
  echo "  logs kept: $WORK/logs/"
fi
[ "$KEEP" -eq 0 ] && [ "$bad" -eq 0 ] && rm -rf "$WORK"
[ -n "$FIXED" ] && exit 1
exit 0
