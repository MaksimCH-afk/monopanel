FROM node:20-alpine

WORKDIR /app

# Install production deps first (better layer caching)
COPY package.json package-lock.json* ./
RUN npm install --omit=dev

COPY . .

ENV PORT=3340
EXPOSE 3340

CMD ["node", "src/server.js"]
