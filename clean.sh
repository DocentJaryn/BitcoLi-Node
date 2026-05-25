#!/bin/bash

echo "🧹 Cleaning Docker cache..."

docker system prune -a -f
docker builder prune -a -f

echo "✅ Done!"
