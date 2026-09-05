.DEFAULT_GOAL := help

.PHONY: help up down install seed reset shell wp status verify pot deploy-package edm-probe edm-status

help:
	@echo "make install  Ortamı kurar ve pilot veriyi yükler"
	@echo "make up       Konteynerleri başlatır"
	@echo "make down     Konteynerleri durdurur"
	@echo "make seed     Ayarları ve pilot veriyi tekrar uygular"
	@echo "make reset    Verileri silip sıfırdan iki aşamalı kurar"
	@echo "make shell    WordPress konteynerinde kabuk açar"
	@echo "make wp ARGS='plugin list'  WP-CLI çalıştırır"
	@echo "make verify   Kabul ölçümlerini raporlar"
	@echo "make pot      Tema ve eklenti çeviri kataloglarını üretir"
	@echo "make deploy-package  Veridyen aktarım arşivini üretir"
	@echo "make edm-probe   EDM sağlık kontrolü: salt-okunur bağlantı/kontör/seri probu"
	@echo "make edm-status  Sandbox'ta gönderilmiş belgenin EDM durumunu sorgular (salt-okunur)"

up:
	@./scripts/ensure-env.sh
	docker compose up -d --wait db wordpress wp-cron

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

pot:
	docker compose run --rm wp-cli wp i18n make-pot /var/www/html/wp-content/themes/kuka-island-child /var/www/html/wp-content/themes/kuka-island-child/languages/kuka-island.pot --domain=kuka-island
	docker compose run --rm wp-cli wp i18n make-pot /var/www/html/wp-content/plugins/kuka-island-core /var/www/html/wp-content/plugins/kuka-island-core/languages/kuka-island-core.pot --domain=kuka-island-core
	docker compose run --rm wp-cli wp i18n make-pot /var/www/html/wp-content/plugins/kuka-island-edm /var/www/html/wp-content/plugins/kuka-island-edm/languages/kuka-island-edm.pot --domain=kuka-island-edm
	docker compose run --rm wp-cli wp i18n make-pot /var/www/html/wp-content/plugins/kuka-island-shipping-automation /var/www/html/wp-content/plugins/kuka-island-shipping-automation/languages/kuka-island-shipping-automation.pot --domain=kuka-island-shipping-automation

deploy-package:
	@./scripts/build-deploy-package.sh

# Her ikisi de salt-okunur: hiçbir belge göndermez, hiçbir kayıt yazmaz.
# Ayrıntı ve yorumlama: docs/EDM_BAKIM_HAFIZASI.md, docs/EDM_AKTIVASYON_REHBERI.md
edm-probe:
	@./scripts/edm-test-run.sh test-edm-sandbox.php

edm-status:
	@./scripts/edm-sandbox-send-run.sh status=confirm
