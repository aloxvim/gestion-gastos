FROM node:24.22.3-alpine AS build

WORKDIR /app

# Recibir variables de entorno como argumentos
ARG VITE_GITHUB_REPO_URL
ENV VITE_GITHUB_REPO_URL=$VITE_GITHUB_REPO_URL

COPY package*.json ./
RUN npm install

COPY . .
RUN npm run build

# Servidor Nginx para producción
FROM nginx:alpine

COPY --from=build /app/dist /usr/share/nginx/html
COPY nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]