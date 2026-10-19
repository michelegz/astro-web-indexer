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

# =============================================================================
# Compose file selection
# -----------------------------------------------------------------------------
# Every `docker compose` call below goes through one of these two. Passing any
# -f turns OFF Compose's automatic loading of docker-compose.override.yml, which
# is the point: without a -f, Compose merges that file over docker-compose.yml
# on its own, and the merge can REPLACE a scalar. The dev layer sets
# `image: nginx:1.27`, so a plain `docker compose build` builds the nginx image
# and tags it nginx:1.27 -- the ghcr.io/.../astro-web-indexer-nginx:<version>
# tag is never produced, and `save`/`push`, which only name images and never
# touch Compose, then ship the previous build's nginx without complaining.
#
# compose_base: the release artefacts. `build` uses it ALONE, so what gets built
#               and tagged cannot depend on a developer's local stack.
# compose_live: the lifecycle commands, with the dev layer on top. It is what
#               mounts ./src in vivo, and it is tracked (docker-compose.dev.yml),
#               so a fresh clone gets the same development stack. Being additive
#               here is safe: the image tags were already set by compose_base
#               before `up` runs.
# =============================================================================
compose_base() {
    docker compose -f docker-compose.yml "$@"
}

compose_live() {
    warn_legacy_override
    if [ -f docker-compose.dev.yml ]; then
        docker compose -f docker-compose.yml -f docker-compose.dev.yml "$@"
    else
        docker compose -f docker-compose.yml "$@"
    fi
}

# A leftover docker-compose.override.yml is dead weight: Compose used to
# auto-load that name and build.sh now names its files explicitly, so the file
# has no effect at all. Saying so is cheaper than the bug report - and the
# advice matters, because the obvious fix ("rename it to docker-compose.dev.yml")
# would OVERWRITE the shared dev layer, which is tracked, with a personal copy.
warn_legacy_override() {
    if [ -f docker-compose.override.yml ]; then
        echo "WARNING: docker-compose.override.yml is not loaded any more." >&2
        echo "         The development setup is docker-compose.dev.yml (tracked)." >&2
        echo "         Delete this file, or fold anything of yours into it." >&2
    fi
}

# =============================================================================
# Registry credentials
# -----------------------------------------------------------------------------
# A push without a credential is refused by the registry only AFTER the layers
# are prepared: twenty lines of "Preparing" and "Waiting" and then one
# "unauthorized: unauthenticated", with nothing saying that the real problem
# is a missing login. The check happens before the first push, where the
# answer is still one line long.
#
# The credential can live in the config file (a plain "ghcr.io" entry) or in a
# credential helper. When there is a helper the push is left alone: the helper
# is the only one that knows what it holds, and a wrong guess here would
# block a push that would have worked.
# =============================================================================
ghcr_credential_present() {
    local cfg="${DOCKER_CONFIG:-$HOME/.docker}/config.json"
    [ -f "$cfg" ] || return 1
    if grep -q '"ghcr\.io"' "$cfg"; then
        return 0
    fi
    if grep -q '"credsStore"\|"credHelpers"' "$cfg"; then
        return 0
    fi
    return 1
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
        compose_base build $NO_CACHE_FLAG --build-arg AWI_VERSION="$AWI_VERSION"

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

        compose_live up -d
        echo "Build complete. Astro Web Indexer is running."
        ;;
    start)
        compose_live up -d
        ;;
    stop)
        compose_live down
        ;;
    logs)
        compose_live logs -f
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

        # A push without a credential dies at the registry, after the layers
        # are prepared, with an "unauthorized" that says nothing about the
        # login that is missing. Caught here, where the answer is one line.
        if ! ghcr_credential_present; then
            echo "Error: no credential for ghcr.io in ${DOCKER_CONFIG:-$HOME/.docker}/config.json." >&2
            echo "       The registry refuses an anonymous push with 'unauthorized'." >&2
            echo "       Log in with a token that has the 'write:packages' scope:" >&2
            echo "         echo \$GH_PAT | docker login ghcr.io -u <github-user> --password-stdin" >&2
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
