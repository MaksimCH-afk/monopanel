# Single-stage image — simple and reliable for a local dev tool.
FROM node:22-slim

# Prisma needs openssl available at runtime.
RUN apt-get update -y && apt-get install -y openssl ca-certificates && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Install dependencies first for better layer caching. The prisma schema must
# be present before `npm install` because the postinstall hook runs
# `prisma generate`, which needs prisma/schema.prisma.
COPY package.json package-lock.json* ./
COPY prisma ./prisma
RUN npm install

# App source.
COPY . .

# Generate Prisma client and build the Next.js app.
ENV NEXT_TELEMETRY_DISABLED=1
RUN npx prisma generate && npm run build

# Data directory for SQLite db + generated images (mounted as a volume).
RUN mkdir -p /data
ENV DATA_DIR=/data
ENV DATABASE_URL=file:/data/imagegen.db

EXPOSE 3000

# On start: apply the schema to the (possibly empty) volume db, seed defaults, then serve.
CMD ["sh", "-c", "npx prisma db push --skip-generate && npx tsx prisma/seed.ts && npm run start"]
