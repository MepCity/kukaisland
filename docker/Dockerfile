FROM wordpress:php8.3-apache@sha256:7f1f307f9b651246d4084ff8978ecf4515ebcc9106c62e2fa364c76f8ab7ee56

# ext-soap eklentisini güvenli ve tekrarlanabilir biçimde derle ve kur
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libxml2-dev \
    ; \
    docker-php-ext-install soap; \
    apt-get purge -y --auto-remove libxml2-dev; \
    rm -rf /var/lib/apt/lists/*

# WP-CLI ikilisini SHA-pinned resmi WordPress CLI imajından kopyala (güvenilir ve değişmez kaynak)
COPY --from=wordpress:cli-php8.3@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586 /usr/local/bin/wp /usr/local/bin/wp
