#!/bin/bash

# Run tests with increased memory limit
docker run --rm \
    -v $(pwd):/app \
    -w /app \
    -m 1g \
    registry.netresearch.de/support/typo3-13/build:latest \
    bash -c "
        # Set PHP memory limit
        echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/memory.ini
        
        # Run CGL check
        echo '=== Running Code Style Check ==='
        composer run ci:test:php:cgl
        
        # Run Lint
        echo '=== Running PHP Lint ==='
        composer run ci:test:php:lint
        
        # Run PHPStan with memory limit
        echo '=== Running PHPStan ==='
        php -d memory_limit=512M .build/vendor/bin/phpstan analyze \
            --configuration Build/phpstan-docker.neon \
            --memory-limit=512M \
            --no-progress
        
        # Run Rector
        echo '=== Running Rector ==='
        composer run ci:test:php:rector
    "
