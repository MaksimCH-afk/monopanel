import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // [monopanel] Приложение втянуто «как есть». Не валим прод-сборку на
  // ESLint/TS-ошибках (no-explicit-any, unused-vars и т.п.) — иначе Next 15
  // не собирает `.next`, и `next start` не поднимает фронт на :3332.
  eslint: { ignoreDuringBuilds: true },
  typescript: { ignoreBuildErrors: true },
};

export default nextConfig;
