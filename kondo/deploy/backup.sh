#!/bin/sh
# Cópia de segurança diária da base de dados e dos ficheiros do Kondo.
# Agendar com: sudo crontab -e  ->  0 3 * * * /opt/kondo/deploy/backup.sh
set -e
DEST=/var/backups/kondo
mkdir -p "$DEST"
sqlite3 /var/lib/kondo/kondo.db ".backup '$DEST/kondo-$(date +%F).db'"
tar -czf "$DEST/uploads-$(date +%F).tar.gz" -C /var/lib/kondo uploads
find "$DEST" -type f -mtime +30 -delete
