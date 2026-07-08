# Taskway — container image for cloud deploy (Render / Railway / Fly.io).
FROM php:8.2-cli

# Extensions Taskway needs (curl + openssl are already bundled in the base image).
RUN apt-get update \
 && apt-get install -y --no-install-recommends libonig-dev \
 && docker-php-ext-install pdo_sqlite mbstring \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

# Store the SQLite DB on a persistent volume mounted here (see platform notes below).
ENV TASKWAY_DATA_DIR=/data
ENV PORT=8080
RUN mkdir -p /data

EXPOSE 8080
# Hosts inject $PORT; the built-in PHP server serves the whole app.
CMD php -S 0.0.0.0:${PORT} -t /app
