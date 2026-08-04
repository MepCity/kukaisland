.DEFAULT_GOAL := help

.PHONY: help up down install seed reset shell wp status verify

help:
	@echo "make install  Ortamı kurar ve pilot veriyi yükler"
	@echo "make up       Konteynerleri başlatır"
	@echo "make down     Konteynerleri durdurur"
	@echo "make seed     Ayarları ve pilot veriyi tekrar uygular"
	@echo "make reset    Verileri silip sıfırdan iki aşamalı kurar"
	@echo "make shell    WordPress konteynerinde kabuk açar"
	@echo "make wp ARGS='plugin list'  WP-CLI çalıştırır"
	@echo "make verify   Kabul ölçümlerini raporlar"

up:
	@./scripts/ensure-env.sh
	docker compose up -d --wait db wordpress

down:
	docker compose down

install:
	@./scripts/install.sh

seed:
	@./scripts/seed.sh

reset:
	@./scripts/reset.sh

shell:
	docker compose exec wordpress bash

wp:
	docker compose run --rm wp-cli wp $(ARGS)

status:
	docker compose ps

verify:
	@./scripts/verify.sh
