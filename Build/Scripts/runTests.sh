#!/usr/bin/env bash

#
# TYPO3 Extension Test Runner for netresearch/nr-image-optimize
#
# Runs test suites and quality tools inside Docker containers using
# ghcr.io/typo3/core-testing-* images. This ensures consistent environments
# across local development and CI, including required PHP extensions
# (GD, Imagick, etc.) that may not be available on the host.
#
# Based on the TYPO3 core runTests.sh pattern.
#

set -e

# Extension root directory (two levels up from this script)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# Network and container naming (per-run to avoid conflicts with concurrent runs)
SUFFIX="$(date +%s%N)"
NETWORK="nr-image-optimize-${SUFFIX}"

# Defaults
SUITE=""
DBMS="sqlite"
PHP_VERSION="8.4"
EXTRA_TEST_OPTIONS=""
XDEBUG=""
UPDATE_IMAGES=""
HELP=""

# Container runtime: prefer docker, fall back to podman
CONTAINER_BIN="docker"
if ! command -v docker &>/dev/null; then
    if command -v podman &>/dev/null; then
        CONTAINER_BIN="podman"
    else
        echo "ERROR: Neither docker nor podman found in PATH." >&2
        exit 1
    fi
fi

# Detect non-TTY for CI environments
CI_PARAMS=""
if [ -t 0 ] && [ -t 1 ]; then
    CI_PARAMS="-it"
fi

# Linux user handling to prevent root-owned files
USERSET=""
if [ "$(uname)" != "Darwin" ]; then
    USERSET="--user $(id -u):$(id -g)"
fi

usage() {
    cat << EOF
TYPO3 Extension Test Runner — nr-image-optimize (Docker-based)

Runs test suites and quality tools inside TYPO3 core-testing Docker containers.

Usage: $(basename "$0") -s <suite> [options] [-- <extra-args>]

Required:
    -s <suite>      Test suite to run:
                        unit          PHPUnit unit tests
                        functional    PHPUnit functional tests
                        acceptance    PHPUnit acceptance tests
                        fuzz          PHPUnit fuzz/property-based tests
                        mutation      Infection mutation testing (unit tests only)
                        mutation-full Infection with unit+functional+acceptance tests
                        lint          PHP syntax linting
                        phpstan       PHPStan static analysis
                        cgl           PHP-CS-Fixer (dry-run by default)
                        cgl:fix       PHP-CS-Fixer (apply fixes)
                        rector        Rector (dry-run by default)
                        rector:fix    Rector (apply changes)
                        fractor       Fractor (dry-run by default)
                        fractor:fix   Fractor (apply changes)
                        ci            Full CI suite (lint, cgl, phpstan, rector, fractor, unit)
                        all           All tests and quality checks

Options:
    -p <version>    PHP version: 8.2, 8.3, 8.4, 8.5 (default: ${PHP_VERSION})
    -d <dbms>       Database for functional tests:
                        sqlite    (default) SQLite via pdo_sqlite
                        mariadb   MariaDB 10
                        mysql     MySQL 8.0
                        postgres  PostgreSQL 16
    -x              Enable Xdebug (for debugging test runs)
    -u              Pull latest Docker images before running
    -h              Show this help message

    --              All arguments after -- are passed to the underlying tool
                    (e.g., -- --filter=testMethodName for PHPUnit)

Examples:
    $(basename "$0") -s unit
    $(basename "$0") -s functional -d mariadb
    $(basename "$0") -s phpstan -p 8.3
    $(basename "$0") -s cgl                    # dry-run (default)
    $(basename "$0") -s cgl:fix                # apply fixes
    $(basename "$0") -s unit -x                # with Xdebug
    $(basename "$0") -s unit -- --filter=testFoo
    $(basename "$0") -s ci
    $(basename "$0") -s all -u                 # update images first

EOF
}

# Parse options. Everything after -- goes to EXTRA_TEST_OPTIONS.
while [ $# -gt 0 ]; do
    case "$1" in
        -s) SUITE="$2"; shift 2 ;;
        -p) PHP_VERSION="$2"; shift 2 ;;
        -d) DBMS="$2"; shift 2 ;;
        -x) XDEBUG="yes"; shift ;;
        -u) UPDATE_IMAGES="yes"; shift ;;
        -h) HELP="yes"; shift ;;
        --) shift; EXTRA_TEST_OPTIONS="$*"; break ;;
        *)  echo "ERROR: Unknown option: $1" >&2; usage; exit 1 ;;
    esac
done

if [ "${HELP}" = "yes" ]; then
    usage
    exit 0
fi

if [ -z "${SUITE}" ]; then
    echo "ERROR: Missing required -s <suite> argument." >&2
    echo ""
    usage
    exit 1
fi

# Validate PHP version
case "${PHP_VERSION}" in
    8.2|8.3|8.4|8.5) ;;
    *) echo "ERROR: Unsupported PHP version: ${PHP_VERSION}. Supported: 8.2, 8.3, 8.4, 8.5" >&2; exit 1 ;;
esac

# Validate DBMS
case "${DBMS}" in
    sqlite|mariadb|mysql|postgres) ;;
    *) echo "ERROR: Unsupported database: ${DBMS}. Supported: sqlite, mariadb, mysql, postgres" >&2; exit 1 ;;
esac

# Docker images
IMAGE_PHP="ghcr.io/typo3/core-testing-$(echo "php${PHP_VERSION}" | sed -e 's/\.//'):latest"
IMAGE_ALPINE="docker.io/alpine:3.22"
IMAGE_MARIADB="docker.io/mariadb:10"
IMAGE_MYSQL="docker.io/mysql:8.0"
IMAGE_POSTGRES="docker.io/postgres:16-alpine"

# Update images if requested
if [ "${UPDATE_IMAGES}" = "yes" ]; then
    echo "Pulling latest Docker images..."
    ${CONTAINER_BIN} pull "${IMAGE_PHP}"
    case "${DBMS}" in
        mariadb)  ${CONTAINER_BIN} pull "${IMAGE_MARIADB}" ;;
        mysql)    ${CONTAINER_BIN} pull "${IMAGE_MYSQL}" ;;
        postgres) ${CONTAINER_BIN} pull "${IMAGE_POSTGRES}" ;;
    esac
    echo ""
fi

# Common container parameters
CONTAINER_COMMON_PARAMS="--rm ${CI_PARAMS} --network ${NETWORK} -v ${ROOT_DIR}:${ROOT_DIR} -w ${ROOT_DIR}"

# Xdebug environment
XDEBUG_MODE=""
XDEBUG_CONFIG=""
if [ "${XDEBUG}" = "yes" ]; then
    XDEBUG_MODE="-e XDEBUG_MODE=debug"
    XDEBUG_CONFIG="client_host=host.docker.internal"
fi

SUITE_EXIT_CODE=0

#
# Wait for a TCP port inside the Docker network by running nc from an Alpine container.
# Follows the TYPO3 core pattern.
#
waitFor() {
    local HOST=${1}
    local PORT=${2}
    local TESTCOMMAND="
        COUNT=0;
        while ! nc -z ${HOST} ${PORT}; do
            if [ \"\${COUNT}\" -gt 10 ]; then
                echo \"Can not connect to ${HOST} port ${PORT}. Aborting.\";
                exit 1;
            fi;
            sleep 1;
            COUNT=\$((COUNT + 1));
        done;
    "
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name wait-for-${SUFFIX} ${IMAGE_ALPINE} /bin/sh -c "${TESTCOMMAND}"
    if [[ $? -gt 0 ]]; then
        kill -SIGINT -$$
    fi
}

#
# Clean up containers attached to the test network and remove the network.
#
cleanUp() {
    ATTACHED_CONTAINERS=$(${CONTAINER_BIN} ps --filter network=${NETWORK} --format='{{.Names}}' 2>/dev/null)
    for ATTACHED_CONTAINER in ${ATTACHED_CONTAINERS}; do
        ${CONTAINER_BIN} kill "${ATTACHED_CONTAINER}" >/dev/null 2>&1 || true
    done
    ${CONTAINER_BIN} network rm "${NETWORK}" 2>/dev/null || true
}

# Ensure cleanup on exit
trap cleanUp EXIT

# Create the Docker network (ignore error if it already exists)
${CONTAINER_BIN} network create "${NETWORK}" 2>/dev/null || true

# --- Suite execution ---

case "${SUITE}" in
    unit)
        # shellcheck disable=SC2086
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} ${USERSET} --name unit-${SUFFIX} \
            ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" \
            ${IMAGE_PHP} php .build/bin/phpunit -c Build/UnitTests.xml ${EXTRA_TEST_OPTIONS}
        SUITE_EXIT_CODE=$?
        ;;

    functional)
        case "${DBMS}" in
            sqlite)
                # shellcheck disable=SC2086
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} ${USERSET} --name functional-${SUFFIX} \
                    ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" \
                    -e typo3DatabaseDriver=pdo_sqlite \
                    ${IMAGE_PHP} php .build/bin/phpunit -c Build/FunctionalTests.xml ${EXTRA_TEST_OPTIONS}
                SUITE_EXIT_CODE=$?
                ;;
            mariadb)
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mariadb-func-${SUFFIX} --network ${NETWORK} \
                    -d -e MYSQL_ROOT_PASSWORD=funcp \
                    --tmpfs /var/lib/mysql/:rw,noexec,nosuid \
                    ${IMAGE_MARIADB} >/dev/null
                waitFor mariadb-func-${SUFFIX} 3306
                # shellcheck disable=SC2086
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} ${USERSET} --name functional-${SUFFIX} \
                    ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" \
                    -e typo3DatabaseDriver=mysqli \
                    -e typo3DatabaseName=func_test \
                    -e typo3DatabaseUsername=root \
                    -e typo3DatabaseHost=mariadb-func-${SUFFIX} \
                    -e typo3DatabasePassword=funcp \
                    ${IMAGE_PHP} php .build/bin/phpunit -c Build/FunctionalTests.xml ${EXTRA_TEST_OPTIONS}
                SUITE_EXIT_CODE=$?
                ;;
            mysql)
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mysql-func-${SUFFIX} --network ${NETWORK} \
                    -d -e MYSQL_ROOT_PASSWORD=funcp \
                    --tmpfs /var/lib/mysql/:rw,noexec,nosuid \
                    ${IMAGE_MYSQL} >/dev/null
                waitFor mysql-func-${SUFFIX} 3306
                # shellcheck disable=SC2086
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} ${USERSET} --name functional-${SUFFIX} \
                    ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" \
                    -e typo3DatabaseDriver=mysqli \
                    -e typo3DatabaseName=func_test \
                    -e typo3DatabaseUsername=root \
                    -e typo3DatabaseHost=mysql-func-${SUFFIX} \
                    -e typo3DatabasePassword=funcp \
                    ${IMAGE_PHP} php .build/bin/phpunit -c Build/FunctionalTests.xml ${EXTRA_TEST_OPTIONS}
                SUITE_EXIT_CODE=$?
                ;;
            postgres)
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name postgres-func-${SUFFIX} --network ${NETWORK} \
                    -d -e POSTGRES_PASSWORD=funcp -e POSTGRES_USER=funcu \
                    --tmpfs /var/lib/postgresql/data:rw,noexec,nosuid \
                    ${IMAGE_POSTGRES} >/dev/null
                waitFor postgres-func-${SUFFIX} 5432
                # shellcheck disable=SC2086
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} ${USERSET} --name functional-${SUFFIX} \
                    ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" \
                    -e typo3DatabaseDriver=pdo_pgsql \
                    -e typo3DatabaseName=bamboo \
                    -e typo3DatabaseUsername=funcu \
                    -e typo3DatabaseHost=postgres-func-${SUFFIX} \
                    -e typo3DatabasePassword=funcp \
                    ${IMAGE_PHP} php .build/bin/phpunit -c Build/FunctionalTests.xml ${EXTRA_TEST_OPTIONS}
                SUITE_EXIT_CODE=$?
                ;;
        esac
        ;;

    acceptance)
        # shellcheck disable=SC2086
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} ${USERSET} --name acceptance-${SUFFIX} \
            ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" \
            ${IMAGE_PHP} php .build/bin/phpunit -c Build/AcceptanceTests.xml ${EXTRA_TEST_OPTIONS}
        SUITE_EXIT_CODE=$?
        ;;

    fuzz)
        # shellcheck disable=SC2086
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} ${USERSET} --name fuzz-${SUFFIX} \
            ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" \
            ${IMAGE_PHP} php .build/bin/phpunit -c Build/FuzzTests.xml --no-coverage ${EXTRA_TEST_OPTIONS}
        SUITE_EXIT_CODE=$?
        ;;

    mutation)
        # shellcheck disable=SC2086
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} ${USERSET} --name mutation-${SUFFIX} \
            -e XDEBUG_MODE=coverage \
            ${IMAGE_PHP} php .build/bin/infection \
            --configuration=infection.json5 --threads=4 --no-progress --show-mutations ${EXTRA_TEST_OPTIONS}
        SUITE_EXIT_CODE=$?
        ;;

    mutation-full)
        # Mutation testing with unit + functional + acceptance tests.
        # Uses the combined PHPUnit config (Build/Infection/phpunit.xml).
        # shellcheck disable=SC2086
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} ${USERSET} --name mutation-full-${SUFFIX} \
            -e XDEBUG_MODE=coverage -e typo3DatabaseDriver=pdo_sqlite \
            ${IMAGE_PHP} php .build/bin/infection \
            --configuration=infection-full.json5 --threads=4 --no-progress --show-mutations ${EXTRA_TEST_OPTIONS}
        SUITE_EXIT_CODE=$?
        ;;

    lint)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} ${USERSET} --name lint-${SUFFIX} \
            ${IMAGE_PHP} php .build/bin/phplint --configuration Build/.phplint.yml
        SUITE_EXIT_CODE=$?
        ;;

    phpstan)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} ${USERSET} --name phpstan-${SUFFIX} \
            ${IMAGE_PHP} php .build/bin/phpstan analyse -c Build/phpstan.neon --memory-limit=-1
        SUITE_EXIT_CODE=$?
        ;;

    cgl)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} ${USERSET} --name cgl-${SUFFIX} \
            ${IMAGE_PHP} php .build/bin/php-cs-fixer fix \
            --dry-run --diff --verbose \
            --config=Build/.php-cs-fixer.dist.php \
            --cache-file .build/.php-cs-fixer.cache
        SUITE_EXIT_CODE=$?
        ;;

    cgl:fix)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} ${USERSET} --name cgl-fix-${SUFFIX} \
            ${IMAGE_PHP} php .build/bin/php-cs-fixer fix \
            --diff --verbose \
            --config=Build/.php-cs-fixer.dist.php \
            --cache-file .build/.php-cs-fixer.cache
        SUITE_EXIT_CODE=$?
        ;;

    rector)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} ${USERSET} --name rector-${SUFFIX} \
            ${IMAGE_PHP} php .build/bin/rector process --config Build/rector.php --dry-run
        SUITE_EXIT_CODE=$?
        ;;

    rector:fix)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} ${USERSET} --name rector-fix-${SUFFIX} \
            ${IMAGE_PHP} php .build/bin/rector process --config Build/rector.php
        SUITE_EXIT_CODE=$?
        ;;

    fractor)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} ${USERSET} --name fractor-${SUFFIX} \
            ${IMAGE_PHP} php .build/bin/fractor process --config Build/fractor.php --dry-run
        SUITE_EXIT_CODE=$?
        ;;

    fractor:fix)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} ${USERSET} --name fractor-fix-${SUFFIX} \
            ${IMAGE_PHP} php .build/bin/fractor process --config Build/fractor.php
        SUITE_EXIT_CODE=$?
        ;;

    ci)
        echo "=== CI Suite ==="
        echo ""
        for s in lint cgl phpstan rector fractor unit; do
            echo "--- Running: ${s} ---"
            "${BASH_SOURCE[0]}" -s "${s}" -p "${PHP_VERSION}" || SUITE_EXIT_CODE=$?
            if [ ${SUITE_EXIT_CODE} -ne 0 ]; then
                echo "FAILED: ${s} (exit code ${SUITE_EXIT_CODE})"
                exit ${SUITE_EXIT_CODE}
            fi
            echo ""
        done
        echo "=== CI Suite completed ==="
        ;;

    all)
        echo "=== Full Suite ==="
        echo ""
        for s in lint cgl phpstan rector fractor unit functional acceptance; do
            echo "--- Running: ${s} ---"
            "${BASH_SOURCE[0]}" -s "${s}" -p "${PHP_VERSION}" -d "${DBMS}" || SUITE_EXIT_CODE=$?
            if [ ${SUITE_EXIT_CODE} -ne 0 ]; then
                echo "FAILED: ${s} (exit code ${SUITE_EXIT_CODE})"
                exit ${SUITE_EXIT_CODE}
            fi
            echo ""
        done
        echo "=== Full Suite completed ==="
        ;;

    *)
        echo "ERROR: Unknown suite: ${SUITE}" >&2
        echo ""
        usage
        exit 1
        ;;
esac

exit ${SUITE_EXIT_CODE}
