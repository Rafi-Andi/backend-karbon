# KarbonKita backend — shortcut perintah Docker Compose.
#
# Makefile ini HANYA membungkus perintah asli; semua `docker compose ...`
# tetap bisa dipakai langsung seperti biasa. Jalankan dari folder backend/.
#
# Contoh:
#   make up                        # build + jalankan semua service
#   make artisan cmd="migrate --force"
#   make artisan cmd="key:generate --show"
#   make logs                      # log service app
#   make curl-up                   # cek /up (PORT=8080 default)
#   make curl-up PORT=8081          # kalau WEB_PORT diganti
#
# Variabel PORT default 8080 mengikuti WEB_PORT default di compose.
# Kalau WEB_PORT di .env diganti, samakan saat panggil target curl-up.

PORT ?= 8080

.PHONY: up build down ps logs logs-all artisan shell key migrate restart curl-up backup-db backup-storage help

help:
	@echo "Target: up build down ps logs logs-all artisan shell key migrate restart curl-up backup-db backup-storage"
	@echo "Contoh: make artisan cmd=\"migrate --force\" | make curl-up PORT=8081"

up:
	docker compose up -d --build

build:
	docker compose build

down:
	docker compose down

ps:
	docker compose ps

logs:
	docker compose logs -f app

logs-all:
	docker compose logs -f app queue web

# make artisan cmd="<perintah artisan>"
artisan:
	docker compose exec app php artisan $(cmd)

shell:
	docker compose exec app bash

key:
	docker compose exec app php artisan key:generate --show

migrate:
	docker compose exec app php artisan migrate --force

restart:
	docker compose restart app

curl-up:
	curl -I http://127.0.0.1:$(PORT)/up

backup-db:
	docker compose exec -T db mysqldump -uroot -p"$${MYSQL_ROOT_PASSWORD}" karbonkita | gzip > ../backup-$$(date +%F).sql.gz

backup-storage:
	docker run --rm -v karbonkita_storage_app:/data -v "$$PWD/..:/backup" alpine tar czf /backup/storage-$$(date +%F).tar.gz -C /data .
