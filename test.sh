#!/bin/bash

# Test script that forces test database
export DB_DATABASE=test

echo "Running tests with test database: $DB_DATABASE"
php artisan test "$@"
