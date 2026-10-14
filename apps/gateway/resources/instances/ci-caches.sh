# Orbit @orbit/ci-caches: imports the main caches of a successful CI run into this checkout (docs/reference/instance-setup.md).
# The Gateway appends `commit`, a work directory with one ZIP archive per Composer project, and the call.
# bin/tia-cache import-ci checks each archive and writes the project's Pest TIA graph and its Pint and PHPStan caches
# where they are absent. An archive that does not fit the checkout exits 3: the project's checks run cold, with the
# reason in this log. Any other failure fails the step.
set -eu

import_ci_caches() {
    if [ ! -x bin/tia-cache ]; then
        echo 'bin/tia-cache is missing: this checkout cannot import the CI main caches.' >&2
        return 1
    fi
    for archive in "$work"/*.zip; do
        directory=${archive%.zip}
        python3 - "$archive" "$directory" <<'PYTHON'
import sys
import zipfile

with zipfile.ZipFile(sys.argv[1]) as archive:
    entries = archive.infolist()
    # ZipFile bounds each extracted member by its declared file_size and checks its CRC.
    # Inspect all entries before extraction, so a small compressed ZIP cannot fill the disk.
    if len(entries) > 1024 or sum(entry.file_size for entry in entries) > 64 * 1024 * 1024:
        sys.exit('CI cache archive exceeds the extraction limits.')
    archive.extractall(sys.argv[2])
PYTHON
        project=$(php -r '$m = json_decode((string) file_get_contents($argv[1]), true); echo is_array($m) && is_string($m["project"] ?? null) ? $m["project"] : "";' "$directory/manifest.json")
        status=0
        bin/tia-cache import-ci --project "$project" --artifact "$directory" --commit "$commit" || status=$?
        if [ "$status" -ne 0 ] && [ "$status" -ne 3 ]; then
            return "$status"
        fi
    done
    for pest in apps/*/tests/Pest.php packages/*/tests/Pest.php; do
        [ -f "$pest" ] || continue
        project=${pest%/tests/Pest.php}
        [ -f "$project/.orbit-tia/graph.json" ] || echo "[$project] has no Pest TIA graph: its tests run cold."
    done
}
