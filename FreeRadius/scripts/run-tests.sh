#!/bin/bash
set -e

echo "Starting containers..."
docker-compose up -d

echo "Waiting for services to be ready..."
sleep 20

echo "Container status:"
docker-compose ps

echo "Testing RADIUS server..."

# Test from within the FreeRADIUS container
docker exec freeradiustp radtest testing password 127.0.0.1 0 testing123

# Test from host to container
docker run --rm --network=$(basename $(pwd))_backend 2stacks/radtest radtest testing password freeradius 0 testing123

echo "Test completed successfully!"