#!/usr/bin/env bash
set -e

# =============================================================================
# Astro Web Indexer - Cross-Platform Build Script
# =============================================================================
# This script manages the build and lifecycle of the Docker containers.
# The application version is automatically calculated from Git
# and passed as a build-arg AWI_VERSION to the Dockerfile.
#
# Usage:
#   ./build.sh build     # Build and start containers, update version
#   ./build.sh start     # Start containers without rebuilding
#   ./build.sh stop      # Stop containers
#   ./build.sh logs      # Follow container logs
#   ./build.sh clean     # Stop containers and remove the generated VERSION file
# =============================================================================

# Function to get Git version
get_version() {
    if git describe --tags --always --dirty >/dev/null 2>&1; then
        git describe --tags --always --dirty
    else
        git rev-parse --short HEAD
    fi
}

AWI_VERSION=$(get_version)
echo "Detected version: $AWI_VERSION"
export AWI_VERSION

# Command to execute (default: help)
COMMAND=${1:-help}

case "$COMMAND" in
    build)
        echo "Building Docker images with AWI_VERSION=$AWI_VERSION..."
        
        NO_CACHE_FLAG=""
        if [ "$2" = "--no-cache" ]; then
            echo "Forcing a clean build with --no-cache..."
            NO_CACHE_FLAG="--no-cache"
        fi

        # Build the Tailwind CSS
        echo "Building Tailwind CSS..."
        npm run build

        docker compose build $NO_CACHE_FLAG --build-arg AWI_VERSION="$AWI_VERSION"
        docker compose up -d
        echo "Build complete. Astro Web Indexer is running."
        ;;
    start)
        docker compose up -d
        ;;
    stop)
        docker compose down
        ;;
    logs)
        docker compose logs -f
        ;;
    save)
        echo "Saving Docker images with tag $AWI_VERSION..."
        if [ -z "$AWI_VERSION" ]; then
            echo "Error: Could not determine version. Aborting."
            exit 1
        fi
        
        OUTPUT_FILE="awi-images-${AWI_VERSION// /-}.tar"
        
        echo "Exporting images to ${OUTPUT_FILE}..."
        
        docker save -o "$OUTPUT_FILE" \
            "astro-web-indexer-nginx:${AWI_VERSION}" \
            "astro-web-indexer-php:${AWI_VERSION}" \
            "astro-web-indexer-python:${AWI_VERSION}" \
            "astro-web-indexer-mariadb:${AWI_VERSION}"
            
        echo "Images saved successfully."
        echo "You can load them on another machine using: docker load -i ${OUTPUT_FILE}"
        ;;
    help|-h|--help)
        echo "Usage: $0 [build|start|stop|logs|save]"
        echo ""
        echo "Commands:"
        echo "  build    Build and start containers (AWI_VERSION passed as build-arg)."
        echo "  start    Start containers without rebuilding."
        echo "  stop     Stop containers."
        echo "  logs     Follow logs of running containers."
        echo "  save     Save the versioned Docker images to a .tar file."
        ;;
    *)
        echo "Unknown command: $COMMAND"
        echo "Use '$0 help' for usage."
        exit 1
        ;;
esac
