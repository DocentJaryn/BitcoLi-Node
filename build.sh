#!/bin/bash

set -e

IMAGE="bitcoli-app"
USER="docentjaryn"
VERSION="0.0.8"

echo "🧱 Building $IMAGE:$VERSION"

docker buildx build \
  --platform linux/amd64,linux/arm64 \
  -t $USER/$IMAGE:$VERSION \
  -t $USER/$IMAGE:latest \
  --push .

echo "🎉 Build complete!"
