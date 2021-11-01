#!/bin/bash
set -eou pipefail

COVERAGE=1
PARAMS=()

while [ $# -ne 0 ]; do
    key="$1"
    case "$key" in
        --no-coverage)
            COVERAGE=0
            shift
            ;;
        -*)
            echo "usage: $0 [--no-coverage] targets ..."
            exit 1
            ;;
        *)
            PARAMS+=("$key")
            shift
            ;;
    esac
done

rm -rf $PROMETHEUS_MULTIPROC_DIR
mkdir -p $PROMETHEUS_MULTIPROC_DIR

# Initialize the new database; ignore errors.
python -m aurweb.initdb 2>/dev/null || \
    (echo "Error: aurweb.initdb failed; already initialized?" && /bin/true)

# Run test_initdb ahead of time, which clears out the database,
# in case of previous failures which stopped the test suite before
# finishing the ends of some test fixtures.
eatmydata -- pytest test/test_initdb.py

# Run pytest with optional targets in front of it.
eatmydata -- make -C test "${PARAMS[@]}" pytest

# By default, report coverage and move it into cache.
if [ $COVERAGE -eq 1 ]; then
    make -C test coverage

    # /cache is mounted as a volume. Copy coverage into it.
    # Users can then sanitize the coverage locally in their
    # aurweb root directory: ./util/fix-coverage ./cache/.coverage
    rm -f /cache/.coverage
    cp -v .coverage /cache/.coverage
    chmod 666 /cache/.coverage
fi
