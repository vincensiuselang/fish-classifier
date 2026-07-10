#!/bin/sh
# ============================================================
# 1) Dengerin di $PORT kalau platform (Railway/Render) ngasih, default 80.
# 2) Auto-import skema DB (idempotent) sebelum Apache start.
# ============================================================
set -e

PORT="${PORT:-80}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/\*:80>/*:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Auto-migrate: coba beberapa kali (nunggu service MySQL siap).
echo "[entrypoint] menjalankan migrasi database..."
i=1
while [ "$i" -le 10 ]; do
  if php /var/www/html/tools/migrate.php; then
    echo "[entrypoint] migrasi selesai."
    break
  fi
  echo "[entrypoint] DB belum siap (percobaan ${i}/10), tunggu 3s..."
  i=$((i + 1))
  sleep 3
done

exec apache2-foreground
