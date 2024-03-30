#!/bin/bash

#
# Install selenium server for functional web testing

set -e
set -x

# Per actions in the tests.yaml file
#
# Current Versions for Ref
# Selenium 3.141.59 jar
# Chrome 123.0.6312.58
# ChromeDriver 123.0.6312.58

echo "Ensuring Selenium Started"
SELENIUM_HUB_URL='http://127.0.0.1:4444'
wget --retry-connrefused --tries=120 --waitretry=3 --output-file=/dev/null "$SELENIUM_HUB_URL/wd/hub/status" -O /dev/null

# Test to see if the selenium server really did start
if [[ ! $? -eq 0 ]]
then
    echo "Selenium Failed"

    # Useful for debugging
    cat /tmp/selenium.log
else
    echo "Selenium Success"

    # Copy phpunit_coverage.php into the webserver's document root directory.
    cp ./vendor/phpunit/phpunit-selenium/PHPUnit/Extensions/SeleniumCommon/phpunit_coverage.php .

    # Copy RemoteCoverage.php back to vendor, this version supports phpunit RawCodeCoverageData
    sudo cp ./tests/RemoteCoverage.php ./vendor/phpunit/phpunit-selenium/PHPUnit/Extensions/SeleniumCommon

    # This keeps triggering in tests for the 2 second rule, lets try to fix that
    sudo sed -i -e "s|spamProtection('login');|//spamProtection('login');|g" ./sources/ElkArte/Controller/Auth.php

    # Run the phpunit selenium tests
    vendor/bin/phpunit --verbose --debug --configuration .github/phpunit-webtest.xml

    # Agents will merge all coverage data...
    if [[ "${GITHUB_EVENT_NAME}" == "pull_request" ]]
    then
        bash <(curl -s https://codecov.io/bash) -s "/tmp" -f '*.clover'
    fi
fi