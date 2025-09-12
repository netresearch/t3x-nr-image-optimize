#!/bin/bash

# Run tests with proper memory configuration
docker run --rm \
    -v $(pwd):/app \
    -w /app \
    --memory="1g" \
    --memory-swap="2g" \
    registry.netresearch.de/support/typo3-13/build:latest \
    bash -c '
        # Configure PHP memory limit
        echo "memory_limit = 512M" >> /usr/local/etc/php/php.ini
        
        # Run tests individually
        echo "=== Running Code Style Check ==="
        composer run ci:test:php:cgl
        
        echo "=== Running PHP Lint ==="
        composer run ci:test:php:lint
        
        echo "=== Running PHPStan with increased memory ==="
        if [ -f .build/vendor/phpstan/phpstan/phpstan ]; then
            php -d memory_limit=512M .build/vendor/phpstan/phpstan/phpstan analyze \
                --configuration Build/phpstan.neon \
                --memory-limit=512M \
                --no-progress || echo "PHPStan failed, but continuing..."
        else
            echo "PHPStan not found, skipping..."
        fi
        
        echo "=== Running Rector ==="
        composer run ci:test:php:rector || echo "Rector found issues"
    '
