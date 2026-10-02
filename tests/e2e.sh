#!/usr/bin/env bash
#
# End-to-end tests: install a fixture "wordpress-core" package into real
# Composer projects and check where it lands (or that unsafe dirs are refused).
#
# Usage: tests/e2e.sh

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/wpci-e2e.XXXXXX")"
trap '[[ -n "${KEEP_WORK:-}" ]] || rm -rf "$WORK"' EXIT

pass=0
fail=0
ok() { echo "  ok   - $1"; pass=$((pass + 1)); }
not_ok() { echo "  FAIL - $1"; fail=$((fail + 1)); }

# Fixture core packages (path repositories, mirrored rather than symlinked).
make_core() {
	local name="$1" dir="$WORK/fixtures/${1//\//-}"
	mkdir -p "$dir"
	echo '<?php // fixture' >"$dir/wp-load.php"
	echo "$name" >"$dir/NAME"
	jq -n --arg name "$name" '{name: $name, version: "1.0.0", type: "wordpress-core",
		require: {"klyp/wordpress-core-installer": "*"}}' >"$dir/composer.json"
	echo "$dir"
}
CORE_A="$(make_core test/core-a)"
CORE_B="$(make_core test/core-b)"

# new_project <name> <require json> [extra json]
new_project() {
	local dir="$WORK/$1" extra="${3:-}"
	[[ -n "$extra" ]] || extra='{}'
	mkdir -p "$dir"
	jq -n \
		--arg installer "$REPO_ROOT" --arg a "$CORE_A" --arg b "$CORE_B" \
		--argjson require "$2" --argjson extra "$extra" \
		'{
			name: "test/project",
			repositories: [
				{type: "path", url: $installer, options: {symlink: false}},
				{type: "path", url: $a, options: {symlink: false}},
				{type: "path", url: $b, options: {symlink: false}}
			],
			require: $require,
			"minimum-stability": "dev",
			"prefer-stable": true,
			config: {"allow-plugins": {"klyp/wordpress-core-installer": true}},
			extra: $extra
		}' >"$dir/composer.json"
	echo "$dir"
}

# COLUMNS keeps Composer from wrapping error messages across lines.
composer_in() { (cd "$1" && shift && COLUMNS=1000 composer --no-interaction --no-ansi "$@" >"$WORK/out.log" 2>&1); }

expect_installed() { # <label> <project> <dir>
	if composer_in "$2" install && [[ -f "$2/$3/wp-load.php" ]] && [[ ! -e "$2/vendor/test/core-a" ]]; then
		ok "$1"
	else
		not_ok "$1"
		cat "$WORK/out.log"
	fi
}

expect_refused() { # <label> <project> <message>
	if ! composer_in "$2" install && grep -qF "$3" "$WORK/out.log"; then
		ok "$1"
	else
		not_ok "$1"
		cat "$WORK/out.log"
	fi
}

echo "Composer $(composer --version 2>/dev/null | awk '{print $3}'), PHP $(php -r 'echo PHP_VERSION;')"

echo "Install directory"
p="$(new_project default '{"test/core-a": "1.0.0"}')"
expect_installed 'defaults to wordpress/' "$p" wordpress

p="$(new_project string '{"test/core-a": "1.0.0"}' '{"wordpress-install-dir": "wp"}')"
expect_installed 'string extra installs to wp/' "$p" wp

p="$(new_project nested '{"test/core-a": "1.0.0"}' '{"wordpress-install-dir": "./public/wp/"}')"
expect_installed 'nested path with ./ and trailing slash' "$p" public/wp

p="$(new_project map '{"test/core-a": "1.0.0", "test/core-b": "1.0.0"}' '{"wordpress-install-dir": {"test/core-a": "wp", "test/core-b": "wp-b"}}')"
if composer_in "$p" install && [[ -f "$p/wp/wp-load.php" && -f "$p/wp-b/wp-load.php" ]]; then
	ok 'map extra installs each package to its own dir'
else
	not_ok 'map extra installs each package to its own dir'
	cat "$WORK/out.log"
fi

echo "Uninstall"
p="$(new_project remove '{"test/core-a": "1.0.0"}' '{"wordpress-install-dir": "wp"}')"
touch "$p/keep.txt"
if composer_in "$p" install && composer_in "$p" remove test/core-a && [[ ! -e "$p/wp" && -f "$p/keep.txt" ]]; then
	ok 'removing the package deletes only its directory'
else
	not_ok 'removing the package deletes only its directory'
	cat "$WORK/out.log"
fi

echo "Unsafe directories are refused"
for case in '.|project root' './|project root' '../outside|outside the project' 'wp/../..|outside the project' \
	'vendor|vendor directory' 'Vendor|vendor directory' '/tmp/wp|absolute paths' 'C:\\wp|absolute paths' \
	"file://$WORK/outside/wp|stream wrappers" 'phar://x.phar/wp|stream wrappers' 'C:wp|drive letters'; do
	dir="${case%%|*}"
	msg="${case#*|}"
	p="$(new_project "unsafe-$pass-$fail" '{"test/core-a": "1.0.0"}' "$(jq -n --arg d "$dir" '{"wordpress-install-dir": $d}')")"
	expect_refused "\"$dir\" ($msg)" "$p" "$msg"
done

p="$(new_project nested-vendor '{"test/core-a": "1.0.0"}' '{"wordpress-install-dir": "lib"}')"
jq '.config["vendor-dir"] = "lib/vendor"' "$p/composer.json" >"$p/c.json" && mv "$p/c.json" "$p/composer.json"
expect_refused '"lib" with vendor-dir lib/vendor (contains the vendor directory)' "$p" 'contains the vendor directory'

p="$(new_project empty '{"test/core-a": "1.0.0"}' '{"wordpress-install-dir": ""}')"
expect_refused '"" (empty)' "$p" 'must be a non-empty directory name'

echo "Shared directories"
both='{"test/core-a": "1.0.0", "test/core-b": "1.0.0"}'
p="$(new_project shared-string "$both" '{"wordpress-install-dir": "wp"}')"
expect_refused 'two packages with one string dir' "$p" 'cannot share an install directory'

p="$(new_project shared-map "$both" '{"wordpress-install-dir": {"test/core-a": "wp", "test/core-b": "./wp/"}}')"
expect_refused 'two packages mapped to the same dir' "$p" 'cannot share an install directory'

p="$(new_project shared-nested "$both" '{"wordpress-install-dir": {"test/core-a": "wp", "test/core-b": "wp/b"}}')"
expect_refused 'one package nested inside another' "$p" 'cannot share an install directory'

p="$(new_project shared-later '{"test/core-a": "1.0.0"}' '{"wordpress-install-dir": "wp"}')"
if composer_in "$p" install && ! composer_in "$p" require test/core-b:1.0.0 \
	&& grep -qF 'cannot share an install directory' "$WORK/out.log" && [[ -f "$p/wp/wp-load.php" ]]; then
	ok 'adding a second package to an installed dir is refused'
else
	not_ok 'adding a second package to an installed dir is refused'
	cat "$WORK/out.log"
fi

p="$(new_project swap '{"test/core-a": "1.0.0"}' '{"wordpress-install-dir": "wp"}')"
if composer_in "$p" install \
	&& jq '.require = {"test/core-b": "1.0.0"}' "$p/composer.json" >"$p/c.json" && mv "$p/c.json" "$p/composer.json" \
	&& composer_in "$p" update && [[ "$(cat "$p/wp/NAME")" == test/core-b ]]; then
	ok 'replacing one package with another in the same dir'
else
	not_ok 'replacing one package with another in the same dir'
	cat "$WORK/out.log"
fi

echo
echo "$pass passed, $fail failed"
[[ $fail -eq 0 ]]
