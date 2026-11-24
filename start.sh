#!/usr/bin/env bash
# One-click launcher: starts the stack with Docker and opens the app.
set -u
cd "$(dirname "$0")"
URL="http://localhost:8000"

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is not installed. Install Docker Desktop, start it and run this again."
  exit 1
fi
if ! docker info >/dev/null 2>&1; then
  echo "The Docker engine is not running. Start Docker Desktop and run this again."
  exit 1
fi

echo "Starting containers (the first run installs dependencies, give it a few minutes)..."
docker compose up -d --build || { echo "docker compose failed."; exit 1; }

echo "Waiting for $URL/up (up to 3 minutes)..."
ready=0
for i in $(seq 1 36); do
  code=$(curl -s -o /dev/null -w '%{http_code}' "$URL/up" 2>/dev/null || true)
  if [ "$code" = "200" ]; then ready=1; break; fi
  echo "  [$i/36] not ready yet (HTTP ${code:-none}), retrying in 5 s"
  sleep 5
done

if [ "$ready" != "1" ]; then
  echo "The app did not become ready in time. Check: docker compose logs -f app"
  exit 1
fi

echo "Ready: $URL"
if command -v open >/dev/null 2>&1; then open "$URL"
elif command -v xdg-open >/dev/null 2>&1; then xdg-open "$URL" >/dev/null 2>&1
elif command -v start >/dev/null 2>&1; then start "$URL"
fi

cat <<'T'

What to try:
  Generate a sample CSV:  docker compose exec app php artisan products:generate-csv 10000 --dirty
                          (written to storage/app/samples, then drag it onto the page)
  Run the CLI demo:       docker compose exec app composer demo
  Stop everything:        docker compose down
T
