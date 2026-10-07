#!/usr/bin/env bash
# Eseguire dalla root, dopo composer install --no-dev e npm run build.
set -euo pipefail

output=${1:?Specificare una directory temporanea esterna al repository}
root=$(pwd -P)
mkdir -p "$output"
output=$(cd "$output" && pwd -P)
case "$output/" in
  "$root/"*) echo 'La directory temporanea deve essere esterna al repository.' >&2; exit 1 ;;
esac
if [[ -e "$output/deploy" || -e "$output/zeromagazzino-deploy.zip" ]]; then
  echo 'Usare una directory temporanea nuova.' >&2
  exit 1
fi
test -f vendor/autoload.php
test -f public/build/manifest.json

mkdir -p "$output/deploy"
# Allowlist di root: nessun file personale, helper, documento o env nella release.
tar -cf - \
  --exclude='.git' --exclude='.github' --exclude='.gitignore' \
  --exclude='.env' --exclude='.env.*' --exclude='auth.json' \
  --exclude='tests' --exclude='Tests' --exclude='node_modules' --exclude='docs' \
  --exclude='*.log' --exclude='*.tmp' --exclude='*.bak' \
  --exclude='README*' --exclude='CHANGELOG*' \
  --exclude='bootstrap/cache' --exclude='public/hot' --exclude='public/storage' \
  --exclude='database/*.sqlite' --exclude='database/*.sqlite-*' \
  app bootstrap config database public resources routes vendor artisan composer.json composer.lock \
  | tar -xf - -C "$output/deploy"

# Storage nuovo e vuoto: mai copiare upload, log, sessioni o cache della macchina build.
mkdir -p "$output/deploy"/{storage/app/private,storage/app/public,storage/framework/cache/data,storage/framework/cache/locks,storage/framework/sessions,storage/framework/views,storage/logs,bootstrap/cache}
if [[ -n $(find "$output/deploy" -type l -print -quit) ]]; then
  echo 'Il pacchetto non può contenere symlink.' >&2
  exit 1
fi
(
  cd "$output/deploy"
  zip -q -r "$output/zeromagazzino-deploy.zip" .
)
unzip -tq "$output/zeromagazzino-deploy.zip"
echo 'Pacchetto pronto: zeromagazzino-deploy.zip'
