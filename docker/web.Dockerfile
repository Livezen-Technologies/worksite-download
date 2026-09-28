# update-web: builds the React app and serves it with nginx (§3.5).
FROM node:22-bookworm-slim AS build
WORKDIR /srv/frontend
COPY frontend/package.json frontend/package-lock.json* ./
RUN npm ci
COPY frontend/ ./
COPY VERSION /srv/VERSION
RUN npm run build

FROM nginx:1.27-alpine
COPY nginx/site.conf /etc/nginx/conf.d/default.conf
COPY nginx/security-headers.conf /etc/nginx/snippets/security-headers.conf
COPY --from=build /srv/frontend/dist /srv/www
