FROM php:8.3-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        python3 \
        python3-pip \
        libreoffice-writer \
        libreoffice-calc \
        fonts-dejavu-core \
    && docker-php-ext-install zip \
    && pip3 install --no-cache-dir --break-system-packages openpyxl \
    && rm -rf /var/lib/apt/lists/*

ENV SOFFICE_BIN=soffice
ENV PYTHON_BIN=python3

WORKDIR /app

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
