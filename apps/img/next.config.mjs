/** @type {import('next').NextConfig} */
const nextConfig = {
  // Image data URLs and disk-served images can be large; allow generous body size.
  experimental: {
    serverActions: {
      bodySizeLimit: "25mb",
    },
  },
};

export default nextConfig;
