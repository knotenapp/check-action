# Knoten architecture-gate runner (published as knotenapp/check-action).
#
# The image *is* the Knoten app: its entrypoint runs `php artisan knoten:check`
# against a project mounted at the CI workspace. The analyzer reads the target's
# PHP source statically — it never installs or runs it — so the target only needs
# to be checked out, not built.
#
# Knoten itself isn't a Composer package, so the app is cloned from its repo at a
# pinned ref. Bump KNOTEN_REF to ship a newer analyzer; pin it to a release tag
# (not a moving branch) for reproducible images.
FROM php:8.4-cli-bookworm

ARG KNOTEN_REPO=https://github.com/Williamug/knoten.git
ARG KNOTEN_REF=main

# PHP extensions Laravel + the analyzer need (php-parser needs tokenizer, which
# is built in). install-php-extensions also drops in Composer; git is needed to
# clone the app.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions mbstring dom @composer \
    && apt-get update \
    && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/knoten

# Fetch the analyzer app at the pinned ref (shallow — history isn't needed) and
# install its runtime dependencies. Scripts are skipped because they invoke
# artisan, which is booted in the next layer once caches are cleared.
RUN git clone --depth 1 --branch "${KNOTEN_REF}" "${KNOTEN_REPO}" . \
    && composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader --no-interaction

# The action-specific overlay: the entrypoint and the GitHub annotation
# formatter. Overlaid on top of the cloned app so both live under /opt/knoten.
COPY docker docker

# A bootable app: an env file with a key, the package manifest, writable caches.
# Clear any bootstrap caches first so discovery can't trip over a stale manifest
# that references a require-dev provider absent from this --no-dev image.
RUN cp .env.example .env \
    && rm -f bootstrap/cache/*.php \
    && php artisan key:generate --no-interaction \
    && php artisan package:discover --ansi \
    && chmod -R ug+rwX storage bootstrap/cache \
    && chmod +x docker/knoten-check-entrypoint.sh

ENTRYPOINT ["/opt/knoten/docker/knoten-check-entrypoint.sh"]
