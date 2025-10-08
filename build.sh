#!/usr/bin/env bash
set -e

# =============================================================================
# Astro Web Indexer - Cross-Platform Build Script
# =============================================================================
# This script manages the build and lifecycle of the Docker containers.
# The application version is automatically calculated from Git.
#
# Usage:
#   ./build.sh build [options]  # Build/rebuild and start containers.
#     Options:
#       --no-cache              Force a rebuild without using Docker's cache.
#       --tag-latest            In addition to the version tag, also tag images as 'latest'.
#       --tag-dev               In addition to the version tag, also tag images as 'dev'.
#
#   ./build.sh start            # Start containers without rebuilding.
#   ./build.sh stop             # Stop containers.
#   ./build.sh logs             # Follow container logs.
#   ./build.sh save             # Save the versioned Docker images to a .tar archive.
#   ./build.sh push [options]   # Push built images to GitHub Container Registry.
#     Options:
#       --tag-latest            Push the 'latest' tag if it was created.
#       --tag-dev               Push the 'dev' tag if it was created.
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
        # Parse arguments
        NO_CACHE_FLAG=""
        TAG_LATEST=false
        TAG_DEV=false
        shift # Removes 'build' from the arguments list
        for arg in "$@"; do
            case $arg in
                --tag-latest)
                TAG_LATEST=true
                ;;
                --tag-dev)
                TAG_DEV=true
                ;;
                --no-cache)
                NO_CACHE_FLAG="--no-cache"
                ;;
            esac
        done

        echo "Building Docker images with primary tag: $AWI_VERSION..."
        if [ -n "$NO_CACHE_FLAG" ]; then
            echo "Forcing a clean build with --no-cache..."
        fi

        # Build the Tailwind CSS
        echo "Building Tailwind CSS..."
        npm run build

        # Build the version-tagged images
        docker compose build $NO_CACHE_FLAG --build-arg AWI_VERSION="$AWI_VERSION"

        # Define services to tag
        SERVICES=("nginx" "php" "python" "mariadb")
        IMAGE_OWNER=${IMAGE_OWNER:-michelegz}

        # Add 'latest' tag if requested
        if [ "$TAG_LATEST" = true ]; then
            echo "Adding 'latest' tag to images..."
            for service in "${SERVICES[@]}"; do
                docker tag "ghcr.io/${IMAGE_OWNER}/astro-web-indexer-${service}:$AWI_VERSION" "ghcr.io/${IMAGE_OWNER}/astro-web-indexer-${service}:latest"
            done
        fi

        # Add 'dev' tag if requested
        if [ "$TAG_DEV" = true ]; then
            echo "Adding 'dev' tag to images..."
            for service in "${SERVICES[@]}"; do
                docker tag "ghcr.io/${IMAGE_OWNER}/astro-web-indexer-${service}:$AWI_VERSION" "ghcr.io/${IMAGE_OWNER}/astro-web-indexer-${service}:dev"
            done
        fi

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

        # Define services and image owner to build full image names
        SERVICES=("nginx" "php" "python" "mariadb")
        IMAGE_OWNER=${IMAGE_OWNER:-michelegz}
        IMAGE_NAMES=()

        # Loop through services to build the full image names
        for service in "${SERVICES[@]}"; do
            IMAGE_NAMES+=("ghcr.io/${IMAGE_OWNER}/astro-web-indexer-${service}:$AWI_VERSION")
        done
        
        # Use the array of full image names in the docker save command
        docker save -o "$OUTPUT_FILE" "${IMAGE_NAMES[@]}"
            
        echo "Images saved successfully."
        echo "You can load them on another machine using: docker load -i ${OUTPUT_FILE}"
        ;;
    push)
        echo "Pushing images with tag $AWI_VERSION to GHCR..."
        
        # Check for ghcr.io login
        if ! docker info | grep -q "ghcr.io"; then
            echo "Error: You are not logged into ghcr.io. Please run 'echo \$GH_PAT | docker login ghcr.io -u <username> --password-stdin' first."
            exit 1
        fi

        SERVICES=("nginx" "php" "python" "mariadb")
        IMAGE_OWNER=${IMAGE_OWNER:-michelegz} # Use environment variable or default

        # Push the version tag
        for service in "${SERVICES[@]}"; do
            docker push "ghcr.io/${IMAGE_OWNER}/astro-web-indexer-${service}:$AWI_VERSION"
        done
        echo "Version tag pushed successfully."

        # Additionally push 'latest' or 'dev' tags if they were created
        TAG_LATEST=false
        TAG_DEV=false
        for arg in "$@"; do
            case $arg in
                --tag-latest)
                TAG_LATEST=true
                ;;
                --tag-dev)
                TAG_DEV=true
                ;;
            esac
        done

        if [ "$TAG_LATEST" = true ]; then
            echo "Pushing 'latest' tag..."
            for service in "${SERVICES[@]}"; do
                docker push "ghcr.io/${IMAGE_OWNER}/astro-web-indexer-${service}:latest"
            done
        fi
        if [ "$TAG_DEV" = true ]; then
            echo "Pushing 'dev' tag..."
            for service in "${SERVICES[@]}"; do
                docker push "ghcr.io/${IMAGE_OWNER}/astro-web-indexer-${service}:dev"
            done
        fi
        ;;
    help|-h|--help)
        echo "Usage: $0 [command] [options]"
        echo ""
        echo "Commands:"
        echo "  build         Build and start containers. Options:"
        echo "                  --no-cache    Force a rebuild without using Docker's cache."
        echo "                  --tag-latest  Additionally tag the build as 'latest'."
        echo "                  --tag-dev     Additionally tag the build as 'dev'."
        echo "  start         Start containers without rebuilding."
        echo "  stop          Stop containers."
        echo "  logs          Follow logs of running containers."
        echo "  save          Save the versioned Docker images to a .tar file."
        echo "  push          Push built images to GitHub Container Registry. Options:"
        echo "                  --tag-latest  Push the 'latest' tag as well."
        echo "                  --tag-dev     Push the 'dev' tag as well."
        ;;
    *)
        echo "Unknown command: $COMMAND"
        echo "Use '$0 help' for usage."
        exit 1
        ;;
esac
