#!/bin/bash

# NeoFramework Core Test Runner
# Usage: ./run-tests.sh [option]

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if PHPUnit is available
check_phpunit() {
    if ! command -v ./vendor/bin/phpunit &> /dev/null; then
        print_error "PHPUnit not found. Please run 'composer install' first."
        exit 1
    fi
}

# Show help
show_help() {
    echo "NeoFramework Core Test Runner"
    echo ""
    echo "Usage: $0 [option]"
    echo ""
    echo "Options:"
    echo "  all              Run all tests (default)"
    echo "  request          Run Request tests only"
    echo "  response         Run Response tests only"
    echo "  controller       Run Controller tests only"
    echo "  router           Run Router tests only"
    echo "  jobs             Run Jobs tests only"
    echo "  coverage         Run tests with HTML coverage report"
    echo "  coverage-text    Run tests with text coverage report"
    echo "  quick            Run tests without coverage"
    echo "  verbose          Run tests with verbose output"
    echo "  help             Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0                    # Run all tests"
    echo "  $0 request           # Run only Request tests"
    echo "  $0 coverage          # Run all tests with HTML coverage"
    echo "  $0 verbose           # Run all tests with verbose output"
}

# Run specific test suite
run_test_suite() {
    local suite=$1
    print_status "Running $suite tests..."
    ./vendor/bin/phpunit --testsuite "$suite Tests"
}

# Main execution
main() {
    check_phpunit
    
    case "${1:-all}" in
        "all")
            print_status "Running all tests..."
            ./vendor/bin/phpunit
            ;;
        "request")
            run_test_suite "Request"
            ;;
        "response")
            run_test_suite "Response"
            ;;
        "controller")
            run_test_suite "Controller"
            ;;
        "router")
            run_test_suite "Router"
            ;;
        "jobs")
            run_test_suite "Jobs"
            ;;
        "coverage")
            print_status "Running tests with HTML coverage report..."
            ./vendor/bin/phpunit --coverage-html coverage-html
            print_success "Coverage report generated in coverage-html/"
            ;;
        "coverage-text")
            print_status "Running tests with text coverage report..."
            ./vendor/bin/phpunit --coverage-text
            ;;
        "quick")
            print_status "Running tests without coverage..."
            ./vendor/bin/phpunit --no-coverage
            ;;
        "verbose")
            print_status "Running tests with verbose output..."
            ./vendor/bin/phpunit --verbose
            ;;
        "help"|"-h"|"--help")
            show_help
            exit 0
            ;;
        *)
            print_error "Unknown option: $1"
            echo ""
            show_help
            exit 1
            ;;
    esac
    
    if [ $? -eq 0 ]; then
        print_success "Tests completed successfully!"
    else
        print_error "Tests failed!"
        exit 1
    fi
}

# Run main function with all arguments
main "$@" 