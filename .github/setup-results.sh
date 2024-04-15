#!/bin/bash
#
# run PHPUNIT tests, send to codecov

set -e
set +x

# Build a config string for PHPUnit
CONFIG="--verbose --configuration .github/phpunit-$DB.xml"

# Running PHPUnit tests
vendor/bin/phpunit ${CONFIG}
