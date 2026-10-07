FROM node:24-alpine AS frontend

WORKDIR /app

COPY apps/web/package.json apps/web/package-lock.json ./
RUN npm ci

COPY apps/web/ ./
RUN npm run build

FROM nginx:1.29-alpine

COPY apps/web/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=frontend /app/dist /var/www/public

EXPOSE 8080
