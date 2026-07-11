#!/bin/sh
set -eu
cd "$(dirname "$0")"
PHP_BIN="${PHP_BIN:-php}"
run_test() {
    "$PHP_BIN" -d display_errors=1 -d error_reporting=E_ALL "$1"
}
run_test smoke_reader_helpers.php
run_test smoke_renderer.php
run_test smoke_storage.php
run_test smoke_ext_lifecycle.php
run_test smoke_listener.php
run_test smoke_xlsx_integration.php
run_test smoke_xlsx_support.php
